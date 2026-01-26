<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

class ReflectionDocComment
{
    public const BASE_TYPES = ['string' => true, 'integer' => true, 'float' => true, 'boolean' => true];

    protected string $docComment = '';

    protected ?\ReflectionClass $reflectionClass = null;

    protected \ReflectionProperty|\ReflectionMethod|\ReflectionConstant|null $target = null;

    public function __construct(
        string $docComment,
        ?\ReflectionClass $reflectionClass = null,
        \ReflectionProperty|\ReflectionMethod|\ReflectionConstant|null $target = null
    )
    {
        $this->docComment = $docComment;
        $this->reflectionClass = $reflectionClass;
        $this->target = $target;
    }

    /**
     * Cleans all doc comment special chars and removes all param and response definitions
     * @param bool $forProperty
     * @param bool $includeExamples If false, removes any "@example" blocks (multi-line) from the description.
     * @return string
     */
    public function getDescription(bool $forProperty = false, bool $includeExamples = true): string
    {
        // If there's no doc comment, return an empty string
        if (!$this->docComment) {
            return '';
        }

        // Start with the original doc comment (resolve inheritdoc if possible)
        $doccomment = $this->resolveInheritDoc($this->docComment);

        if (!$includeExamples) {
            // Remove @example blocks (tag + its content) until the next @tag or end of docblock.
            // Must support multi-line examples and Windows line endings (\r\n).
            $doccomment = preg_replace(
                '/(?:\r?\n)\s*\*\s*@example\b.*?(?=(?:\r?\n)\s*\*\s*@\w+\b|(?:\r?\n)\s*\*\/|\*\/\s*$)/si',
                '',
                $doccomment
            );
            // Also handle the case where "@example" is the first meaningful line (no leading newline).
            $doccomment = preg_replace(
                '/^\s*\/\*\*\s*(?:\r?\n)\s*\*\s*@example\b.*?(?=(?:\r?\n)\s*\*\s*@\w+\b|(?:\r?\n)\s*\*\/|\*\/\s*$)/si',
                "/**",
                $doccomment
            );
        }

        // If we're getting the description for a property...
        if ($forProperty) {
            // Remove everything before the @var declaration (including the declaration itself)
            $doccomment = preg_replace('/(.*)\@var\s+[\?0-9a-zA-Z|_\[\]\{\}\\\\]+/s', '', $doccomment);
        }

        // Remove everything after the first @xxx declaration (including the declaration itself)
        // \W (any non-word char) is used to not detect mail@asd.com as @var term
        $doccomment = preg_replace('/\W\@([0-9a-zA-Z_]*)\s+([0-9a-zA-Z_]+)(\s+\$([0-9a-zA-Z_]+))?/is', '', $doccomment);

        // Remove the /** comment delimiter at the start of a line, the */ comment delimiter at the start of a line (possibly preceded by whitespace),
        // and a * character at the start of a line (possibly preceded by whitespace)
        $doccomment = preg_replace('/^\/\*\*|^\s*\*\/|^\s*\*/m', '', $doccomment);

        // Remove leading whitespace from each line
        $doccomment = preg_replace('/^ +/sm', '', $doccomment);

        // Markdown requires newlines to have 2 empty spaces followed by \n
        $doccomment = preg_replace("/\n[^\n]]/sm", "  \n", $doccomment);

        // remove final */
        $doccomment = preg_replace('/\*\/$/', '', $doccomment);

        // Remove leading and trailing whitespace and return the result
        return trim($doccomment);
    }

    /**
     * Resolves {@inheritdoc} / @inheritdoc by walking upstream (parent classes, interfaces, traits).
     *
     * Rules:
     * - If $this->reflectionClass is set and $this->target is null: resolve class-level inheritdoc via parent class doc.
     * - If $this->reflectionClass and $this->target are set: resolve member-level inheritdoc (method/property/constant) via upstream declarations.
     */
    protected function resolveInheritDoc(string $docComment): string
    {
        if (!$docComment) {
            return '';
        }

        if (!$this->containsInheritDoc($docComment)) {
            return $docComment;
        }

        // No class context => nothing to resolve.
        if (!$this->reflectionClass) {
            return $docComment;
        }

        $upstream = null;

        // Class-level doc
        if ($this->target === null) {
            $upstream = $this->findUpstreamClassDoc($this->reflectionClass);
        } else {
            // Member-level doc
            if ($this->target instanceof \ReflectionMethod) {
                $upstream = $this->findUpstreamMethodDoc($this->reflectionClass, $this->target->getName());
            } elseif ($this->target instanceof \ReflectionProperty) {
                $upstream = $this->findUpstreamPropertyDoc($this->reflectionClass, $this->target->getName());
            } elseif ($this->target instanceof \ReflectionConstant) {
                $upstream = $this->findUpstreamConstantDoc($this->reflectionClass, $this->target->getName());
            }
        }

        if (!$upstream) {
            return $docComment;
        }

        // If upstream itself contains inheritdoc, resolve recursively (defensive, capped).
        $resolvedUpstream = $this->resolveInheritDocCapped($upstream, 5);

        // Replace all inheritdoc markers with the upstream docblock.
        return $this->replaceInheritDocMarkers($docComment, $resolvedUpstream);
    }

    /**
     * Checks if a docComment contains {@inheritdoc} or @inheritdoc markers
     *
     * @param string $docComment The docComment to check
     * @return bool True if inheritdoc markers are present, false otherwise
     */
    protected function containsInheritDoc(string $docComment): bool
    {
        // Match both {@inheritdoc} and @inheritdoc (case-insensitive, with optional whitespace)
        return (bool)preg_match('/\{\@\s*inheritdoc\s*\}|\@\s*inheritdoc\b/i', $docComment);
    }

    /**
     * Finds the first non-empty class-level docComment from parent classes
     *
     * @param \ReflectionClass $reflectionClass The class to search from
     * @return string|null The first non-empty parent docComment, or null if none found
     */
    protected function findUpstreamClassDoc(\ReflectionClass $reflectionClass): ?string
    {
        // Walk up the inheritance chain
        $parentClass = $reflectionClass->getParentClass();
        while ($parentClass instanceof \ReflectionClass) {
            $docComment = $parentClass->getDocComment();

            // Return the first non-empty docComment found
            if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                return $docComment;
            }

            $parentClass = $parentClass->getParentClass();
        }

        return null;
    }

    /**
     * Finds the first non-empty method docComment from interfaces, parent classes, or traits
     *
     * Search order: interfaces first (common for contracts), then parent classes, then traits
     *
     * @param \ReflectionClass $reflectionClass The class containing the method
     * @param string $methodName The name of the method to search for
     * @return string|null The first non-empty docComment found, or null if none found
     */
    protected function findUpstreamMethodDoc(\ReflectionClass $reflectionClass, string $methodName): ?string
    {
        // 1) Check interfaces first (common inheritdoc expectation for contracts)
        foreach ($reflectionClass->getInterfaces() as $interface) {
            if ($interface->hasMethod($methodName)) {
                $interfaceMethod = $interface->getMethod($methodName);
                $docComment = $interfaceMethod->getDocComment();

                if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                    return $docComment;
                }
            }
        }

        // 2) Walk up parent classes
        $parentClass = $reflectionClass->getParentClass();
        while ($parentClass instanceof \ReflectionClass) {
            if ($parentClass->hasMethod($methodName)) {
                $parentMethod = $parentClass->getMethod($methodName);
                $docComment = $parentMethod->getDocComment();

                if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                    return $docComment;
                }
            }
            $parentClass = $parentClass->getParentClass();
        }

        // 3) Check traits (from class and all parents)
        foreach ($this->allTraitsRecursive($reflectionClass) as $trait) {
            if ($trait->hasMethod($methodName)) {
                $traitMethod = $trait->getMethod($methodName);
                $docComment = $traitMethod->getDocComment();

                if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                    return $docComment;
                }
            }
        }

        return null;
    }

    /**
     * Collects all traits used by a class and its parents recursively
     *
     * This includes:
     * - Direct traits used by the class
     * - Traits used by parent classes
     * - Traits used by other traits (nested)
     *
     * @param \ReflectionClass $reflectionClass The class to collect traits from
     * @return array<string,\ReflectionTrait> Map of trait names to ReflectionTrait objects
     */
    protected function allTraitsRecursive(\ReflectionClass $reflectionClass): array
    {
        $traits = [];

        // Recursive closure to collect traits and their nested traits
        $collectTraits = static function (\ReflectionClass|\ReflectionTrait $reflection) use (
            &$traits,
            &$collectTraits
        ): void {
            foreach ($reflection->getTraits() as $trait) {
                $traitName = $trait->getName();

                // Avoid processing the same trait twice
                if (!isset($traits[$traitName])) {
                    $traits[$traitName] = $trait;

                    // Recursively collect traits used by this trait
                    $collectTraits($trait);
                }
            }
        };

        // Walk up the inheritance chain and collect traits from each class
        $currentClass = $reflectionClass;
        while ($currentClass instanceof \ReflectionClass) {
            $collectTraits($currentClass);
            $currentClass = $currentClass->getParentClass();
        }

        return $traits;
    }

    /**
     * Finds the first non-empty property docComment from parent classes or traits
     *
     * @param \ReflectionClass $reflectionClass The class containing the property
     * @param string $propertyName The name of the property to search for
     * @return string|null The first non-empty docComment found, or null if none found
     */
    protected function findUpstreamPropertyDoc(\ReflectionClass $reflectionClass, string $propertyName): ?string
    {
        // Walk up parent classes
        $parentClass = $reflectionClass->getParentClass();
        while ($parentClass instanceof \ReflectionClass) {
            if ($parentClass->hasProperty($propertyName)) {
                $parentProperty = $parentClass->getProperty($propertyName);
                $docComment = $parentProperty->getDocComment();

                if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                    return $docComment;
                }
            }
            $parentClass = $parentClass->getParentClass();
        }

        // Check traits (from class and all parents)
        foreach ($this->allTraitsRecursive($reflectionClass) as $trait) {
            if ($trait->hasProperty($propertyName)) {
                $traitProperty = $trait->getProperty($propertyName);
                $docComment = $traitProperty->getDocComment();

                if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                    return $docComment;
                }
            }
        }

        return null;
    }

    /**
     * Finds the first non-empty constant docComment from parent classes or traits
     *
     * @param \ReflectionClass $reflectionClass The class containing the constant
     * @param string $constantName The name of the constant to search for
     * @return string|null The first non-empty docComment found, or null if none found
     */
    protected function findUpstreamConstantDoc(\ReflectionClass $reflectionClass, string $constantName): ?string
    {
        // Walk up parent classes
        $parentClass = $reflectionClass->getParentClass();
        while ($parentClass instanceof \ReflectionClass) {
            if ($parentClass->hasConstant($constantName)) {
                $parentConstant = $parentClass->getReflectionConstant($constantName);

                if ($parentConstant) {
                    $docComment = $parentConstant->getDocComment();

                    if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                        return $docComment;
                    }
                }
            }
            $parentClass = $parentClass->getParentClass();
        }

        // Check traits (from class and all parents)
        foreach ($this->allTraitsRecursive($reflectionClass) as $trait) {
            if ($trait->hasConstant($constantName)) {
                $traitConstant = $trait->getReflectionConstant($constantName);

                if ($traitConstant) {
                    $docComment = $traitConstant->getDocComment();

                    if ($docComment !== false && $docComment !== null && trim($docComment) !== '') {
                        return $docComment;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Recursively resolves {@inheritdoc} markers with depth limit to prevent infinite loops
     *
     * @param string $docComment The docComment to resolve
     * @param int $maxDepth Maximum recursion depth (prevents circular inheritance)
     * @return string Resolved docComment with all inheritdoc markers replaced
     */
    protected function resolveInheritDocCapped(string $docComment, int $maxDepth): string
    {
        $current = $docComment;

        // Iteratively resolve inheritdoc up to maxDepth times
        for ($i = 0; $i < $maxDepth; $i++) {
            if (!$this->containsInheritDoc($current)) {
                // No more inheritdoc markers found
                break;
            }

            // Temporarily swap in the current string for resolution
            $tmp = $this->docComment;
            $this->docComment = $current;
            $current = $this->resolveInheritDoc($this->docComment);
            $this->docComment = $tmp;
        }

        return $current;
    }

    /**
     * Replaces all {@inheritdoc} and @inheritdoc markers with the upstream documentation
     *
     * Extracts the content from the upstream docComment (removing delimiters and leading asterisks)
     * and inserts it in place of the @inheritdoc marker, preserving any additional text
     * in the current docComment.
     *
     * @param string $docComment The docComment containing inheritdoc markers
     * @param string $upstreamDoc The upstream documentation to insert
     * @return string DocComment with all inheritdoc markers replaced
     */
    protected function replaceInheritDocMarkers(string $docComment, string $upstreamDoc): string
    {
        // Extract clean content from upstream doc (remove /** */, leading *, and trim)
        $upstreamContent = $upstreamDoc;

        // Remove opening /** and closing */
        $upstreamContent = preg_replace('/^\s*\/\*\*\s*/', '', $upstreamContent);
        $upstreamContent = preg_replace('/\s*\*\/\s*$/', '', $upstreamContent);

        // Remove leading * from each line
        $upstreamContent = preg_replace('/^\s*\*\s?/m', '', $upstreamContent);

        // Trim the result
        $upstreamContent = trim($upstreamContent);

        // Replace @inheritdoc markers with the clean upstream content
        return preg_replace(
            '/\{\@\s*inheritdoc\s*\}|\@\s*inheritdoc\b/i',
            $upstreamContent,
            $docComment
        ) ?? $docComment;
    }

    /**
     * Returns the first example block (backwards compatible).
     * Prefer using getExamples() for multiple @example tags.
     */
    public function getExample(): string
    {
        return $this->getExamples()[0] ?? '';
    }

    /**
     * Returns all @example blocks as an array (supports multiple tags and multi-line examples).
     *
     * Examples are extracted as raw text (docblock markers and leading "*" removed).
     *
     * @return string[]
     */
    public function getExamples(): array
    {
        if (!$this->docComment) {
            return [];
        }

        $doccomment = $this->resolveInheritDoc($this->docComment);
        if (stripos($doccomment, '@example') === false) {
            return [];
        }

        // Capture each "@example" block until the next "@tag" line or end of docblock.
        // Supports:
        // - multi-line examples
        // - multiple @example tags
        // - @example placed before other tags or at the end
        // - Windows newlines (\r\n)
        preg_match_all(
            '/@example\b(?P<content>.*?)(?=(?:\r?\n)\s*\*\s*@\w+\b|\*\/\s*$)/si',
            $doccomment,
            $matches
        );

        $examples = [];
        foreach (($matches['content'] ?? []) as $content) {
            // Remove leading newline(s)
            $content = preg_replace('/^\s*(?:\r?\n)?/', '', $content);

            // Remove docblock line prefixes like " * "
            $content = preg_replace('/^\s*\*\s?/m', '', $content);

            // Defensive: remove trailing docblock end if captured
            $content = preg_replace('/\*\/\s*$/', '', $content);

            $content = trim($content);
            if ($content !== '') {
                $examples[] = $content;
            }
        }

        return $examples;
    }

    /**
     * Extracts the throw declarations of a method and returns an array of class names
     * @return string[]
     */
    public function getThrowDeclarations(): ?array
    {
        $doccomment = $this->resolveInheritDoc($this->docComment);
        $pattern = '/\@throws\s+(?P<ThrowDeclarations>[0-9a-zA-Z_\{\}\\\\\[\]]+)/m';
        preg_match_all($pattern, $doccomment, $matches);
        if (isset($matches['ThrowDeclarations']) && isset($matches['ThrowDeclarations'][0])) {
            return $matches['ThrowDeclarations'];
        }
        return null;
    }

    /**
     * in case of propertyName is null, returns the type definitions from an property ReflectionDocComment like (at)var SomeType
     * Examples:
     * (at)var string
     * (at)var Cat|Dog
     * (at)var Cat[]|Dog[]
     * (at)var Cat|Dog[] => will be interpreted as array of either cat or dog
     *
     * in case $propertyName is set, we expect to search in a class doccomment for (at)property
     * Examples:
     * (at)property string $propertyName
     * (at)property Cat|Dog $propertyName
     * (at)property Cat[]|Dog[] $propertyName
     * (at)property Cat|Dog[] $propertyName => will be interpreted as array of either cat or dog
     * @return string[]|null
     */
    public function getPropertyTypes(string $propertyName = null): ?array
    {
        if (!$this->docComment) {
            return null;
        }
        $doccomment = $this->resolveInheritDoc($this->docComment);
        if ($propertyName) {
            $lines = explode("\n", $doccomment);
            foreach ($lines as $line) {
                $pattern = '/\@property\s+(?P<PropertyType>[0-9a-zA-Z|_\{\}\\\\\[\]]+)+\s+\$' . $propertyName . '[\s;]*$/';
                preg_match($pattern, $line, $matches);
                if (isset($matches['PropertyType'])) {
                    //replace [] e.g. Cat[]|Dog[] => Cat|Dog
                    $propertyType = preg_replace('/\[\s*\]/', '', $matches['PropertyType']);
                    return explode('|', $propertyType);
                }
            }
            return null;
        } else {
            preg_match('/\@var\s+(?P<PropertyType>[0-9a-zA-Z|_\{\}\\\\\[\]]+)/', $doccomment, $matches);
            if (isset($matches['PropertyType'])) {
                //replace [] e.g. Cat[]|Dog[] => Cat|Dog
                $propertyType = preg_replace('/\[\s*\]/', '', $matches['PropertyType']);
                return explode('|', $propertyType);
            }
            return null;
        }
    }
}