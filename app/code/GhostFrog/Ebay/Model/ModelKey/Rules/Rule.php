<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

abstract class Rule
{
    protected const UNKNOWN_KEY = 'unknown';

    protected static function regex(string $pattern, string $flags = ''): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~u' . $flags;
    }

    protected static function search(string $pattern, string $text, string $flags = ''): ?RegexMatch
    {
        return self::firstMatch(self::regex($pattern, $flags), $text);
    }

    protected static function firstMatch(string $regex, string $text): ?RegexMatch
    {
        if (preg_match($regex, $text, $captures, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        return new RegexMatch(array_values($captures), $text);
    }

    protected static function findMatches(string $pattern, string $text, string $flags = ''): iterable
    {
        preg_match_all(self::regex($pattern, $flags), $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $captures) {
            yield new RegexMatch(array_values($captures), $text);
        }
    }

    protected static function findAll(string $pattern, string $text): array
    {
        $values = [];
        foreach (self::findMatches($pattern, $text) as $match) {
            $groups = array_map(static fn(?string $group): string => $group ?? '', $match->groups());
            $values[] = match (count($groups)) {
                0 => $match->group(),
                1 => $groups[0],
                default => $groups,
            };
        }
        return $values;
    }

    protected static function fullMatch(string $pattern, string $text): bool
    {
        return preg_match(self::regex('\A(?:' . $pattern . ')\z'), $text) === 1;
    }

    protected static function replacePattern(string $pattern, string $replacement, string $text): string
    {
        return preg_replace(self::regex($pattern), $replacement, $text);
    }

    protected static function words(string $text): array
    {
        return preg_split('~\s+~u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    protected static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            $members = array_map(static fn($part) => is_string($part) ? "'" . $part . "'" : self::stringify($part), $value);
            return '[' . implode(', ', $members) . ']';
        }
        return match ($value) {
            null => 'None',
            true => 'True',
            false => 'False',
            default => (string)$value,
        };
    }

    protected static function length(array|string $value): int
    {
        return is_array($value) ? count($value) : mb_strlen($value);
    }

    protected static function contains(array|string $haystack, mixed $needle): bool
    {
        if (is_string($haystack)) {
            return str_contains($haystack, (string)$needle);
        }
        return array_is_list($haystack) ? in_array($needle, $haystack, true) : array_key_exists($needle, $haystack);
    }

    protected static function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }
        return false;
    }

    protected static function any(iterable $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if ($predicate($value)) {
                return true;
            }
        }
        return false;
    }

    protected static function longest(array $values): string
    {
        $longest = '';
        foreach ($values as $value) {
            if (mb_strlen($value) > mb_strlen($longest)) {
                $longest = $value;
            }
        }
        return $longest;
    }

    protected static function longestFirst(array $values): array
    {
        usort($values, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        return $values;
    }

    protected static function iterate(array|string $value): array
    {
        return is_string($value) ? mb_str_split($value) : $value;
    }

    protected static function alphanumeric(string $value): string
    {
        return preg_replace('~[^\p{L}\p{N}]~u', '', $value);
    }

    protected static function isAlphanumeric(string $value): bool
    {
        return preg_match('~\A[\p{L}\p{N}]+\z~u', $value) === 1;
    }

    protected static function isDigit(string $value): bool
    {
        return preg_match('~\A\p{Nd}+\z~u', $value) === 1;
    }

    protected static function withoutAccents(string $text): string
    {
        return preg_replace('~[^\x00-\x7F]~', '', \Normalizer::normalize($text, \Normalizer::FORM_KD));
    }
}
