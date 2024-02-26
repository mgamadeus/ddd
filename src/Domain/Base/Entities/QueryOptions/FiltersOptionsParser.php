<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Infrastructure\Exceptions\BadRequestException;

class FiltersOptionsParser
{
    protected const BRACKETS = ['(', ')'];

    private string $input;
    private int $index;

    private int $inputLength;

    public function __construct(string $input)
    {
        $this->input = trim($input);
        $this->index = 0;
        $this->inputLength = strlen($this->input);
    }

    public function parse(): FiltersOptions
    {
        return $this->parseExpression();
    }

    /**
     * @return FiltersOptions Parses experession recursively
     */
    private function parseExpression(): FiltersOptions
    {
        $left = $this->parseTerm();

        while (true) {
            $operator = null;
            if ($this->match('and')) {
                $right = $this->parseTerm();
                $operator = FiltersOptions::JOIN_OPERATOR_AND;
            } elseif ($this->match('or')) {
                $right = $this->parseTerm();
                $operator = FiltersOptions::JOIN_OPERATOR_OR;
            } else {
                break;
            }
            $filterOptions = new FiltersOptions();
            $filterOptions->type = FiltersOptions::TYPE_OPERATION;
            $filterOptions->joinOperator = $operator;
            $filterOptions->add($left);
            $filterOptions->add($right);
            $left = $filterOptions;
        }
        return $left;
    }


    private function parseTerm(): FiltersOptions
    {
        if ($this->match('(')) {
            $expr = $this->parseExpression();
            $this->match(')');
            return $expr;
        } else {
            return $this->parseComparison();
        }
    }

    /**
     * Parses comparison e.g. varName eq 'literal' and returns FilterOptions
     * @return FiltersOptions
     * @throws BadRequestException
     */
    private function parseComparison(): FiltersOptions
    {
        $left = $this->parseIdentifier();
        $operator = $this->parseOperator();
        $right = $this->parseLiteral();
        $filterOptions = new FiltersOptions();
        $filterOptions->type = FiltersOptions::TYPE_EXPRESSION;
        $filterOptions->property = $left;
        $filterOptions->operator = $operator;
        $filterOptions->value = $right;
        return $filterOptions;
    }

    /**
     * Parses identifier e.g. "startsAt", "varName"
     * @return string
     * @throws BadRequestException
     */
    private function parseIdentifier(): string
    {
        $tIndex = $this->index + $this->getWhiteSpaceCharactersCountAtBeginning();
        $identifier = '';
        while (true) {
            // identifier can contain aA0-9._
            $curChar = $this->input[$tIndex] ?? null;
            if (ctype_alnum($curChar) || in_array($curChar, ['.', '_'])) {
                $tIndex++;
                $identifier .= $curChar;
                continue;
            }
            break;
        }
        if ($identifier) {
            $this->index = $tIndex;
            return $identifier;
        }
        throw new BadRequestException(
            "Parsing of FiltersOptions failed: variable/filter identifier (e.g. varName) expected at index {$this->index} for input: {$this->input}"
        );
    }

    /**
     * Parse Operator e.g. eq, lq, gt ...
     * @return string
     * @throws BadRequestException
     */
    private function parseOperator(): string
    {
        $tIndex = $this->index + $this->getWhiteSpaceCharactersCountAtBeginning();

        // get the next 2 chars of the input as all operators are 2 chars long
        $potentialOperator = strtolower(substr($this->input, $tIndex, 2));
        $tIndex += 2;

        if (isset(FiltersOptions::OPERATORS_TO_DOCTRINE_ALLOCATION[$potentialOperator])) {
            // Check the character immediately after the operator (if it exists)
            $nextChar = $this->input[$tIndex] ?? null;

            // Check if the next character is whitespace or empty
            //// only these characters are valid after an operator
            if ($nextChar === null || ctype_space($nextChar)) {
                // If it is, consume the token and return true
                $this->index = $tIndex;
                return $potentialOperator;
            }
        }
        throw new BadRequestException(
            "Parsing of FiltersOptions failed: operator (e.g. eq, lq, gt ...) expected at index {$this->index} for input: {$this->input}"
        );
    }

    /**
     * Parses literal, literals e.g. varname eq 'literal'
     * Literals can be
     * - numeric (int or float)
     * - string need to be encapsuled in ''
     * - array in json format, e.g. [123,234,"asd"]
     * - null
     * @return string|float|int|array
     * @throws BadRequestException
     */
    private function parseLiteral(): string|float|int|array|null
    {
        $tIndex = $this->index + $this->getWhiteSpaceCharactersCountAtBeginning();

        $literal = '';

        $curChar = $this->input[$tIndex] ?? null;

        // we have 4 types of literals
        // numbers, strings, arrays, null

        // number literal
        if ($curChar && ctype_digit($curChar)) {
            while (true) {
                $literal .= $curChar;
                $tIndex++;
                $curChar = $this->input[$tIndex] ?? null;
                if (!(ctype_digit($curChar) || $curChar == '.') || $curChar === null) {
                    // only valid options after end of number sequence
                    if (!(ctype_space($curChar) || $curChar == ')' || $curChar === null)) {
                        throw new BadRequestException(
                            "Parsing of FiltersOptions failed: invalid number literal at index {$this->index} for input: {$this->input}"
                        );
                    }
                    $this->index = $tIndex;
                    return is_int($literal) ? (int)$literal : (float)$literal;
                }
            }
        } // null
        elseif (strtolower($curChar) == 'n') {
            $substr = strtolower(substr($this->input, $tIndex, 4));
            if ($substr === 'null') {
                $tIndex += 4;
                $this->index = $tIndex;
                return null;
            }
            throw new BadRequestException(
                "Parsing of FiltersOptions failed: string literal without quotes at index {$this->index} for input: {$this->input}"
            );
        } // string literal
        elseif ($curChar == "'") {
            while (true) {
                $prevChar = $curChar;
                $tIndex++;
                // we skip the first one as it is a single quote '
                $curChar = $this->input[$tIndex] ?? null;

                if ($curChar === null) {
                    throw new BadRequestException(
                        "Parsing of FiltersOptions failed: not ending string literal found at index {$this->index} for input: {$this->input}"
                    );
                }

                if ($curChar == "'" && $prevChar !== "\\") {
                    // we are done, return literal
                    $tIndex++;
                    $this->index = $tIndex;
                    return $literal;
                }

                $literal .= $curChar;
            }
        } // array literal
        elseif ($curChar == '[') {
            // Initialize a variable to keep track of whether we're inside a string literal
            $insideString = false;

            $openingBrackets = 0;
            //can be ' or "
            $stringDefiner = false;
            // Parse the array literal character by character
            $prevChar = '';
            while (true) {
                $tIndex++;
                // we skip the first one as it is a single bracket [
                if ($curChar === null) {
                    throw new BadRequestException(
                        "Parsing of FiltersOptions failed: invalid formatted array literal found at index {$this->index} for input: {$this->input}"
                    );
                }
                elseif ($stringDefiner === false && ($curChar === '"' || $curChar === "'")) {
                    // a string starts
                    $stringDefiner = $curChar;
                    // in order to have valid json, we convert ' to "
                    $curChar = '"';
                } elseif ($curChar === $stringDefiner && $prevChar !== "\\") {
                    // a string ends
                    $stringDefiner = false;
                    // in order to have valid json, we convert ' to "
                    $curChar = '"';
                } elseif (!$stringDefiner && $curChar == '[') {
                    $openingBrackets++;
                } elseif (!$stringDefiner && $curChar == ']') {
                    $openingBrackets--;
                }
                $literal .= $curChar;
                if ($openingBrackets == 0) {
                    // we have the end of the array
                    $jsonDecodedLiteral = json_decode($literal);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new BadRequestException(
                            "Parsing of FiltersOptions failed: invalid formatted array literal found at index {$this->index} for input: {$this->input}"
                        );
                    }
                    $this->index = $tIndex;
                    return $jsonDecodedLiteral;
                }
                $prevChar = $curChar;
                $curChar = $this->input[$tIndex] ?? null;
            }
        }
        throw new BadRequestException(
            "Parsing of FiltersOptions failed: literal expected at index {$this->index} for input: {$this->input}"
        );
        // Parse literal (e.g., '2023-01-01')
    }

    /**
     * Check if the current input matches the given token
     * @param string $token
     * @return bool
     */
    private function match(string $token): bool
    {
        $tIndex = $this->index + $this->getWhiteSpaceCharactersCountAtBeginning();

        $tokenLength = strlen($token);

        // Get the part of the input that starts with the current index and has the same length as the token
        $substring = substr($this->input, $tIndex, $tokenLength);

        // operands are not case sensitive
        if (in_array($token, FiltersOptions::OPERATORS)) {
            $substring = strtolower($substring);
        }

        // If the substring matches the token...
        if ($substring === $token) {
            // if we find a bracket, it is irrelevant what comes after
            $tIndex += $tokenLength;
            if (in_array($token, self::BRACKETS)) {
                $this->index = $tIndex;
                return true;
            }
            // Check the character immediately after the token (if it exists)
            $nextChar = $this->input[$tIndex] ?? null;

            // Check if the next character is whitespace, an opening bracket, a closing bracket, or null (end of input)
            // only these characters are valid after an operand
            if ($nextChar === null || ctype_space($nextChar) || $nextChar === '(' || $nextChar === ')') {
                // If it is, consume the token and return true
                $this->index = $tIndex;
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if char at index is whitespace
     * @param int $index
     * @return bool
     */
    private function isWhitespace(int $index): bool
    {
        // Get the current character
        $char = $this->input[$index];
        // Check if it's a whitespace character
        return ctype_space($char);
    }

    /**
     * @return int Returns the number of whitespace characters at the beginning of current index position
     */
    private function getWhiteSpaceCharactersCountAtBeginning(): int
    {
        $tIndex = $this->index;
        while (true) {
            // current index exceeding input length
            if ($tIndex > $this->inputLength - 1) {
                return $tIndex;
            }
            if ($this->isWhitespace($tIndex)) {
                $tIndex++;
            } else {
                break;
            }
        }
        return $tIndex - $this->index;
    }
}