#!/usr/bin/env python3
"""
Skills-first gate for the DDD Core (and module) repos — the skill-reminder concept of consuming apps, adapted: a session must have LOADED the matching ddd-* skill before it creates or changes a file of a category
(policies.json). Placing a class without its skill broke every consuming app once (a service outside a public
Services namespace).

Modes (argv[1]), each reading the hook's JSON on stdin:
  prompt       UserPromptSubmit — inject the topic → skill table as context
  record       PostToolUse on Skill or Read — record the loaded skill for this session; reading a skill's SKILL.md counts
               (module skills are not loadable from the Core session, their SKILL.md is read instead)
  check-file   PreToolUse on Write / Edit / MultiEdit / NotebookEdit — deny when the category's skills are missing
  check-bash   PreToolUse on Bash — the same for files a shell command writes (heredoc, sed -i, perl -pi, python
               open(..., 'w'), cp, mv, tee) and for release commands (git tag)

New file: ALL skills of the category; existing file: ANY one of them. State: state/<session>/<skill>, pruned after 7
days. Never blocks on its own failure (exit 0).
"""
import fnmatch
import json
import os
import re
import sys
import time

HOOK_DIRECTORY = os.path.dirname(os.path.abspath(__file__))
STATE_ROOT = os.path.join(HOOK_DIRECTORY, 'state')
SKILL_NAME_PATTERN = re.compile(r'^[A-Za-z0-9_:-]+$')
# commands that may write ANY path they name; a plain redirect (`>`) writes only its own target (REDIRECT_TARGET), so
# `sed -n … file.php 2>/dev/null` stays a read
WRITE_INDICATORS = re.compile(r"(?:\btee\b|\bsed\s+-i|\bperl\s+-[0-9a-z]*i|open\([^)]*['\"][wa]['\"]|\.write\(|\bcp\b|\bmv\b|\bgit\s+mv\b|\bwrite_text\()")
REDIRECT_TARGET = re.compile(r">{1,2}\s*[\"']?([^\s\"';|&<>()]+)")
PATH_TOKEN = re.compile(r"[\"']?((?:/|\.?[A-Za-z0-9_.-]+/)[^\s\"';|&<>()]*?(?:\.php|SKILL\.md|/references/[^\s\"';|&<>()]+\.md))[\"']?")

PROMPT_CONTEXT = """<system-reminder>
SKILLS FIRST (DDD Core / modules): before designing, placing or changing a class, load the matching ddd-* skills via
the Skill tool and follow them — a class placed from memory or from a brief breaks every consuming app at once.

Topic → skills:
- Service, or any class with dependencies / state / fetched with getService(): ddd-service-specialist (it lives in a
  public …\\Services\\ namespace and ends in Service)
- Entity / EntitySet / value object / ObjectSet: ddd-entity-specialist (+ ddd-rights-specialist for DB repositories)
- Controller / DTO / route / OpenAPI: ddd-endpoint-specialist
- Console command: ddd-cli-command-specialist
- Message / handler / worker: ddd-message-handler-specialist
- Issues log, registry, client errors, source maps, runtime errors: ddd-issues-log-specialist
- QueryOptions: ddd-query-options-specialist · Serializer: ddd-serializer-specialist · Rights: ddd-rights-specialist
- Schema diff / PostgreSQL setup: ddd-database-schema-diff-specialist · Geometry / vectors: ddd-geometry-and-vector-specialist
- Release, version bump, composer update: ddd-composer-update-version (+ ddd-module-orchestrator across modules)
- Skill authoring: ddd-skill-creator
A feature brief's class names and namespaces are proposals — vet them against these skills like an ingest.
Writes to these paths (Write/Edit and shell) are blocked until the skills are loaded in this session.
</system-reminder>"""


def read_input():
    try:
        return json.load(sys.stdin)
    except Exception:
        return {}


def load_policies():
    with open(os.path.join(HOOK_DIRECTORY, 'policies.json')) as policies_file:
        return json.load(policies_file)


def get_state_directory(hook_input):
    session_id = str(hook_input.get('session_id') or 'default')
    session_id = re.sub(r'[^A-Za-z0-9_-]', '_', session_id)
    return os.path.join(STATE_ROOT, session_id)


def get_loaded_skills(hook_input):
    state_directory = get_state_directory(hook_input)
    if not os.path.isdir(state_directory):
        return set()
    loaded_skills = set(os.listdir(state_directory))
    # a plugin-qualified load (plugin:skill) counts for the bare name too
    return loaded_skills | {loaded_skill.split(':')[-1] for loaded_skill in loaded_skills}


def find_file_rule(policies, file_path):
    is_new_file = not os.path.exists(file_path)
    for file_rule in policies['file_rules']:
        if file_rule.get('new_only') and not is_new_file:
            continue
        if any(fnmatch.fnmatch(file_path, pattern) for pattern in file_rule['patterns']):
            return file_rule, is_new_file
    return None, is_new_file


def get_missing_skills(required_skills, loaded_skills, require_all):
    if require_all:
        return [skill for skill in required_skills if skill not in loaded_skills]
    return [] if any(skill in loaded_skills for skill in required_skills) else list(required_skills)


def deny(reason):
    print(json.dumps({'hookSpecificOutput': {
        'hookEventName': 'PreToolUse',
        'permissionDecision': 'deny',
        'permissionDecisionReason': reason,
    }}))


def build_file_violation(policies, loaded_skills, file_path):
    # generated Doctrine models are written by the generator, not designed
    if re.match(r'^DB\w*Model\.php$', os.path.basename(file_path)):
        return None
    file_rule, is_new_file = find_file_rule(policies, file_path)
    violations = []
    if file_rule is not None:
        missing_skills = get_missing_skills(file_rule['skills'], loaded_skills, require_all=is_new_file)
        if missing_skills:
            violations.append('{} {} ({}) needs {}'.format(
                'creating' if is_new_file else 'changing',
                file_rule['category'],
                file_path,
                ' + '.join(missing_skills) if is_new_file else 'one of ' + ' / '.join(missing_skills),
            ))
    for module_rule in policies.get('module_rules', []):
        if any(fnmatch.fnmatch(file_path, pattern) for pattern in module_rule['patterns']):
            missing_skills = get_missing_skills(module_rule['skills'], loaded_skills, require_all=True)
            if missing_skills:
                violations.append('a file of the {} ({}) needs {} (read the module\'s .claude/skills/<name>/SKILL.md)'.format(
                    module_rule['category'], file_path, ' + '.join(missing_skills)))
    return '; '.join(violations) or None


def mode_prompt():
    read_input()
    print(json.dumps({'hookSpecificOutput': {'hookEventName': 'UserPromptSubmit', 'additionalContext': PROMPT_CONTEXT}}))


def mode_record():
    hook_input = read_input()
    tool_input = hook_input.get('tool_input') or {}
    if hook_input.get('tool_name') == 'Skill':
        skill = str(tool_input.get('skill') or '')
    elif hook_input.get('tool_name') == 'Read':
        skill_file_match = re.search(r'/\.claude/skills/([A-Za-z0-9_:-]+)/SKILL\.md$', str(tool_input.get('file_path') or ''))
        skill = skill_file_match.group(1) if skill_file_match else ''
    else:
        return
    if not SKILL_NAME_PATTERN.match(skill):
        return
    state_directory = get_state_directory(hook_input)
    os.makedirs(state_directory, exist_ok=True)
    open(os.path.join(state_directory, skill), 'a').close()
    prune_old_sessions()


def prune_old_sessions():
    cutoff = time.time() - 7 * 86400
    for entry in os.listdir(STATE_ROOT):
        entry_path = os.path.join(STATE_ROOT, entry)
        try:
            if os.path.isdir(entry_path) and os.path.getmtime(entry_path) < cutoff:
                for state_file in os.listdir(entry_path):
                    os.unlink(os.path.join(entry_path, state_file))
                os.rmdir(entry_path)
        except OSError:
            pass


def mode_check_file():
    hook_input = read_input()
    tool_input = hook_input.get('tool_input') or {}
    file_path = str(tool_input.get('file_path') or tool_input.get('notebook_path') or '')
    if not file_path:
        return
    violation = build_file_violation(load_policies(), get_loaded_skills(hook_input), os.path.abspath(file_path))
    if violation:
        deny('SKILLS FIRST — ' + violation + '. Load the skill(s) with the Skill tool in this session, follow them, then retry.')


def mode_check_bash():
    hook_input = read_input()
    command = str((hook_input.get('tool_input') or {}).get('command') or '')
    if not command:
        return
    policies = load_policies()
    loaded_skills = get_loaded_skills(hook_input)
    violations = []
    for command_rule in policies.get('command_rules', []):
        if re.search(command_rule['pattern'], command):
            missing_skills = get_missing_skills(command_rule['skills'], loaded_skills, require_all=True)
            if missing_skills:
                violations.append('{} needs {}'.format(command_rule['category'], ' + '.join(missing_skills)))
    candidate_paths = []
    if WRITE_INDICATORS.search(command):
        candidate_paths = [path_match.group(1) for path_match in PATH_TOKEN.finditer(command)]
    candidate_paths += [redirect_match.group(1) for redirect_match in REDIRECT_TARGET.finditer(command)
                        if PATH_TOKEN.fullmatch(redirect_match.group(1))]
    if candidate_paths:
        working_directory = str(hook_input.get('cwd') or os.getcwd())
        directory_match = re.search(r'\bcd\s+("([^"]+)"|\'([^\']+)\'|(\S+))', command)
        if directory_match:
            working_directory = directory_match.group(2) or directory_match.group(3) or directory_match.group(4)
        seen_paths = set()
        for candidate_path in candidate_paths:
            file_path = os.path.normpath(os.path.join(working_directory, os.path.expanduser(candidate_path)))
            # a path whose directory does not exist is a string in the command, not a write target
            if file_path in seen_paths or not os.path.isdir(os.path.dirname(file_path)):
                continue
            seen_paths.add(file_path)
            violation = build_file_violation(policies, loaded_skills, file_path)
            if violation:
                violations.append(violation)
    if violations:
        deny('SKILLS FIRST — this command ' + '; '.join(sorted(set(violations)))
             + '. Load the skill(s) with the Skill tool in this session, follow them, then retry.')


def main():
    try:
        {'prompt': mode_prompt, 'record': mode_record, 'check-file': mode_check_file, 'check-bash': mode_check_bash}[sys.argv[1]]()
    except Exception as exception:  # a broken gate must never block work
        print('skill_gate: ' + str(exception), file=sys.stderr)
    sys.exit(0)


if __name__ == '__main__':
    main()
