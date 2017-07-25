<?php

namespace Globalia\LaravelScoutMysql\Models;

use Globalia\LaravelScoutMysql\Exceptions\SearchExpressionException;

class SearchExpression
{
    const FILTER = 'filter';

    const BOOST = 'boost';

    private $grammar;

    private $value;

    private $type;

    private $result;

    public function __construct($grammar, $value)
    {
        $this->grammar = $grammar;

        $this->value = $value;

        $this->build();
    }

    public function get()
    {
        return $this->result;
    }

    public function isFilter()
    {
        return $this->type === self::FILTER;
    }

    public function isBoost()
    {
        return $this->type === self::BOOST;
    }

    private function build()
    {
        $grammar = trim($this->grammar);
        $lowerGrammar = strtolower($grammar);
        $chars = str_replace(' ', '', $lowerGrammar);

        if (substr($chars, 0, 6) === 'boost(') {
            if (! preg_match('/^\s*boost\s*\(([[:alnum:]]+)\)\s*$/', $lowerGrammar, $matches)) {
                throw new SearchExpressionException(
                    sprintf('Expression "%s" is not valid.', $this->grammar)
                );
            }

            $this->type = self::BOOST;

            return $this->result = new Boost($matches[1], $this->value);
        }

        $this->type = self::FILTER;

        if (null === $this->value || is_array($this->value)) {
            return $this->result = new Filter($grammar, null, $this->value);
        }

        $normalizedGrammar = str_replace(['  ', ', '], [' ', ','], $lowerGrammar);

        $fourEndedChars = substr($chars, -4);
        if (in_array($fourEndedChars, ['!~~*'], true)) {
            $expression = rtrim(substr($grammar, 0, -4));

            return $this->result = new Filter($expression, $fourEndedChars, $this->value);
        }

        $threeEndedChars = substr($fourEndedChars, 1);
        if (in_array($threeEndedChars, ['<=>', '!~*', '~~*'], true)) {
            $expression = rtrim(substr($grammar, 0, -3));

            return $this->result = new Filter($expression, $threeEndedChars, $this->value);
        }

        $twoEndedChars = substr($threeEndedChars, 1);
        if (in_array($twoEndedChars, ['<=', '>=', '<>', '!=', '<<', '>>', '~*', '!~'], true)) {
            $expression = rtrim(substr($grammar, 0, -2));

            return $this->result = new Filter($expression, $twoEndedChars, $this->value);
        }

        $endedChar = substr($twoEndedChars, 1);
        if (in_array($endedChar, ['=', '<', '>', '&', '|', '^', '~'], true)) {
            $expression = rtrim(substr($grammar, 0, -1));

            return $this->result = new Filter($expression, $endedChar, $this->value);
        }

        if (substr($normalizedGrammar, -9) === ' not like') {
            $expression = rtrim(substr($grammar, 0, -9));
            $operator = 'not like';
        } elseif (substr($normalizedGrammar, -5) === ' like') {
            $expression = rtrim(substr($grammar, 0, -5));
            $operator = 'like';
        } elseif (substr($normalizedGrammar, -10) === ' not ilike') {
            $expression = rtrim(substr($grammar, 0, -10));
            $operator = 'not ilike';
        } elseif (substr($normalizedGrammar, -6) === ' ilike') {
            $expression = rtrim(substr($grammar, 0, -6));
            $operator = 'ilike';
        } elseif (substr($normalizedGrammar, -10) === ' not rlike') {
            $expression = rtrim(substr($grammar, 0, -10));
            $operator = 'not rlike';
        } elseif (substr($normalizedGrammar, -6) === ' rlike') {
            $expression = rtrim(substr($grammar, 0, -6));
            $operator = 'rlike';
        } elseif (substr($normalizedGrammar, -16) === ' not like binary') {
            $expression = rtrim(substr($grammar, 0, -16));
            $operator = 'not like binary';
        } elseif (substr($normalizedGrammar, -12) === ' like binary') {
            $expression = rtrim(substr($grammar, 0, -12));
            $operator = 'like binary';
        } elseif (substr($normalizedGrammar, -13) === ' not between') {
            $expression = rtrim(substr($grammar, 0, -13));
            $operator = 'not between';
        } elseif (substr($normalizedGrammar, -9) === ' between') {
            $expression = rtrim(substr($grammar, 0, -9));
            $operator = 'between';
        } elseif (substr($normalizedGrammar, -11) === ' not regexp') {
            $expression = rtrim(substr($grammar, 0, -11));
            $operator = 'not regexp';
        } elseif (substr($normalizedGrammar, -7) === ' regexp') {
            $expression = rtrim(substr($grammar, 0, -7));
            $operator = 'regexp';
        } elseif (substr($normalizedGrammar, -15) === ' not similar to') {
            $expression = rtrim(substr($grammar, 0, -15));
            $operator = 'not similar to';
        } elseif (substr($normalizedGrammar, -11) === ' similar to') {
            $expression = rtrim(substr($grammar, 0, -11));
            $operator = 'similar to';
        } else {
            $expression = $grammar;
            $operator = '=';
        }

        return $this->result = new Filter($expression, $operator, $this->value);
    }
}
