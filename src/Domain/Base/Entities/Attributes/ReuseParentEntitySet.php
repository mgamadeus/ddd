<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Attributes;

use Attribute;

/**
 * Opt-in marker for entities that extend a Framework (or other root-namespace) Entity
 * **only** to add constants, type-aliases, or other non-persistent declarations — and
 * therefore want to reuse the parent's EntitySet and Service rather than declaring their
 * own pluralized class in the local namespace.
 *
 * By default, {@see EntityTrait::getEntitySetClass()} guards against cross-namespace
 * EntitySet inheritance: e.g. `App\Foo extends DDD\Foo` will **not** automatically resolve
 * to `DDD\Foos` because in most cases an App-side subclass is expected to provide its own
 * App-side EntitySet that wires App-specific service methods. The guard prevents the
 * subtle bug of accidentally routing App entities through framework EntitySets that lack
 * the App's behavior.
 *
 * However, there is a legitimate and common pattern where an App subclass adds **only
 * constants** (e.g. App-specific enum-like values, prompt names, route names), introduces
 * no new persistent properties, and has no App-specific service methods. In that case
 * the App is conceptually saying "I am the framework's entity, plus a few constants",
 * and forcing the developer to create a parallel `App\Foos` class — that just re-exports
 * `DDD\Foos` — is pure boilerplate.
 *
 * Tagging the App subclass with `#[ReuseParentEntitySet]` declares this intent
 * explicitly: `getEntitySetClass()` will fall through to the immediate parent class's
 * EntitySet **across root namespaces**, and `getService()` (which delegates to it)
 * resolves to the parent's service.
 *
 * Example
 * -------
 *
 * Framework-side:
 *   namespace DDD\Domain\AI\Entities\Prompts;
 *   class AIPrompt extends Entity { ... }
 *   class AIPrompts extends EntitySet { public const SERVICE_NAME = AIPromptsService::class; }
 *
 * App-side, before:
 *   namespace App\Domain\AI\Entities\Prompts;
 *   class AIPrompt extends \DDD\Domain\AI\Entities\Prompts\AIPrompt {
 *       public const string SOME_APP_PROMPT = 'X';
 *   }
 *   // Calling App\AIPrompt::getService() returns null — no App\AIPrompts exists.
 *
 * App-side, after:
 *   #[ReuseParentEntitySet]
 *   class AIPrompt extends \DDD\Domain\AI\Entities\Prompts\AIPrompt {
 *       public const string SOME_APP_PROMPT = 'X';
 *   }
 *   // Calling App\AIPrompt::getService() returns the framework's AIPromptsService.
 *
 * When NOT to use this attribute
 * ------------------------------
 *
 * Do not tag a subclass with `#[ReuseParentEntitySet]` if:
 *   - The subclass adds App-specific properties that need to be queryable in lists.
 *   - The subclass needs an App-specific Service with App-only methods.
 *   - The subclass changes the database schema in any way (e.g. adds DatabaseColumn fields).
 *
 * In any of those cases the canonical pattern is to declare a parallel App EntitySet
 * (and Service) — that's why the cross-namespace guard exists in the first place.
 *
 * @see EntityTrait::getEntitySetClass()
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ReuseParentEntitySet
{
    use BaseAttributeTrait;
}
