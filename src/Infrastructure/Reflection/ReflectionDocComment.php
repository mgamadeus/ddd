<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

class ReflectionDocComment
{
    public const BASE_TYPES = ['string' => true, 'integer' => true, 'float' => true, 'boolean' => true];
    private string $docComment = '';

    public function __construct(string $docComment)
    {
        $this->docComment = $docComment;
    }


    /**
     * Cleans all doc comment special chars and removes all param and reponse definitions
     * @param $forProperty
     * @return string
     */
    public function getDescription($forProperty = false): string
    {
        // If there's no doc comment, return an empty string
        if (!$this->docComment) {
            return '';
        }

        // Start with the original doc comment
        $doccomment = $this->docComment;

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

    public function getExample(): string
    {
        if (!$this->docComment) {
            return '';
        }
        $doccomment = $this->docComment;
        if (strpos($doccomment, '@example') === false) {
            return '';
        }
        // remove everythhing before @exmple declaration (including the declaration itself)
        $doccomment = preg_replace('/(.*)\@example/s', '', $doccomment);
        // remove everyting after the fist @xxx declaration (including the declaration itself)
        // \W (any non word char) is used to not detect mail@asd.com as @var term
        $doccomment = preg_replace('/\W\@([0-9a-zA-Z_])(.*)/is', '', $doccomment);
        // clean irrelevant characters
        $doccomment = preg_replace('/(\/\*\*?)|(\*\/)|(\*)*/sm', '', $doccomment);
        // clean trailing and ending whitespace
        $doccomment = preg_replace('/^ +/sm', '', $doccomment);
        return trim($doccomment);
    }

    /**
     * Extracts the throw declarations of a method and returns an array of class names
     * @return string[]
     */
    public function getThrowDeclarations(): ?array
    {
        $pattern = '/\@throws\s+(?P<ThrowDeclarations>[0-9a-zA-Z_\{\}\\\\\[\]]+)/m';
        preg_match_all($pattern, $this->docComment, $matches);
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
        if ($propertyName) {
            $lines = explode("\n", $this->docComment);
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
            preg_match('/\@var\s+(?P<PropertyType>[0-9a-zA-Z|_\{\}\\\\\[\]]+)/', $this->docComment, $matches);
            if (isset($matches['PropertyType'])) {
                //replace [] e.g. Cat[]|Dog[] => Cat|Dog
                $propertyType = preg_replace('/\[\s*\]/', '', $matches['PropertyType']);
                return explode('|', $propertyType);
            }
            return null;
        }
    }
}