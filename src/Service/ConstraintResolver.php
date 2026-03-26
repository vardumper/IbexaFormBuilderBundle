<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Service;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints;

/**
 * Resolves Symfony validator constraints for a form field from two sources:
 *
 * 1. Auto-applied: derived from the field's HTML attribute values
 *    (minlength → Length, type=email → Email, pattern → Regex, etc.)
 *
 * 2. Manual: a JSON array in the `constraints` / `form_builder_constraints`
 *    content field, e.g.:
 *    [{"type":"Length","min":5},{"type":"Regex","pattern":"/^[A-Z]/"}]
 *
 * Returns a list of serialisable definitions instead of live objects so that
 * the result can be stored in a cache entry.  The definitions are converted to
 * real Constraint objects in ContentFormFactory::buildFormFromStructure().
 *
 * @phpstan-type ConstraintDef array{class: class-string, options: array<string, mixed>}
 */
final class ConstraintResolver
{
    /**
     * Constraint short-names accepted in the JSON constraints field.
     * All entries are validated against this allowlist before use.
     *
     * @var array<string, class-string>
     */
    private const ALLOWED = [
        // presence
        'NotBlank' => Constraints\NotBlank::class,
        'IsNull' => Constraints\IsNull::class,
        'IsTrue' => Constraints\IsTrue::class,
        'IsFalse' => Constraints\IsFalse::class,
        // string format
        'Email' => Constraints\Email::class,
        'Url' => Constraints\Url::class,
        'Ip' => Constraints\Ip::class,
        'Regex' => Constraints\Regex::class,
        'Length' => Constraints\Length::class,
        'Uuid' => Constraints\Uuid::class,
        'Hostname' => Constraints\Hostname::class,
        // numeric ranges
        'Range' => Constraints\Range::class,
        'GreaterThan' => Constraints\GreaterThan::class,
        'GreaterThanOrEqual' => Constraints\GreaterThanOrEqual::class,
        'LessThan' => Constraints\LessThan::class,
        'LessThanOrEqual' => Constraints\LessThanOrEqual::class,
        'Positive' => Constraints\Positive::class,
        'PositiveOrZero' => Constraints\PositiveOrZero::class,
        'Negative' => Constraints\Negative::class,
        'NegativeOrZero' => Constraints\NegativeOrZero::class,
        // date / time
        'Date' => Constraints\Date::class,
        'Time' => Constraints\Time::class,
        'DateTime' => Constraints\DateTime::class,
        // financial
        'Iban' => Constraints\Iban::class,
        'Luhn' => Constraints\Luhn::class,
        'CardScheme' => Constraints\CardScheme::class,
    ];

    /**
     * @param array<string, mixed> $fieldValues Already-resolved field values keyed by field
     *                                           identifier, with or without the form_builder_ prefix.
     * @return list<ConstraintDef>
     */
    public function resolve(array $fieldValues): array
    {
        $n = $this->normalize($fieldValues);

        return array_values(array_merge(
            $this->autoConstraints($n),
            $this->manualConstraints((string) ($n['constraints'] ?? '')),
        ));
    }

    // -------------------------------------------------------------------------
    // Auto-constraints derived from HTML-attribute field values
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $v Normalized (prefix-stripped) field values.
     * @return list<ConstraintDef>
     */
    private function autoConstraints(array $v): array
    {
        $c = [];
        $type = (string) ($v['type'] ?? '');

        // Input-type → semantic constraint
        match ($type) {
            'email' => $c[] = ['class' => Constraints\Email::class,    'options' => ['mode' => 'html5']],
            'url' => $c[] = ['class' => Constraints\Url::class,      'options' => []],
            'date' => $c[] = ['class' => Constraints\Date::class,     'options' => []],
            'time' => $c[] = ['class' => Constraints\Time::class,     'options' => []],
            'datetime-local' => $c[] = ['class' => Constraints\DateTime::class, 'options' => ['format' => 'Y-m-d\TH:i']],
            default => null,
        };

        // minlength / maxlength → Length
        $minLen = isset($v['minlength']) && $v['minlength'] !== '' ? (int) $v['minlength'] : null;
        $maxLen = isset($v['maxlength']) && $v['maxlength'] !== '' ? (int) $v['maxlength'] : null;
        if ($minLen !== null || $maxLen !== null) {
            $opts = [];
            if ($minLen !== null) {
                $opts['min'] = $minLen;
            }
            if ($maxLen !== null) {
                $opts['max'] = $maxLen;
            }
            $c[] = ['class' => Constraints\Length::class, 'options' => $opts];
        }

        // min / max on numeric inputs → Range
        if (in_array($type, ['number', 'range'], true)) {
            $min = isset($v['min']) && $v['min'] !== '' ? (float) $v['min'] : null;
            $max = isset($v['max']) && $v['max'] !== '' ? (float) $v['max'] : null;
            if ($min !== null || $max !== null) {
                $opts = [];
                if ($min !== null) {
                    $opts['min'] = $min;
                }
                if ($max !== null) {
                    $opts['max'] = $max;
                }
                $c[] = ['class' => Constraints\Range::class, 'options' => $opts];
            }
        }

        // pattern → Regex
        $pattern = (string) ($v['pattern'] ?? '');
        if ($pattern !== '') {
            if (!str_starts_with($pattern, '/')) {
                $pattern = '/' . addcslashes($pattern, '/') . '/';
            }
            $c[] = ['class' => Constraints\Regex::class, 'options' => ['pattern' => $pattern]];
        }

        // required → NotBlank
        if (!empty($v['required'])) {
            $c[] = ['class' => Constraints\NotBlank::class, 'options' => []];
        }

        return $c;
    }

    // -------------------------------------------------------------------------
    // Manual constraints from the JSON content field
    // -------------------------------------------------------------------------

    /**
     * Parses a JSON string like:
     *   [{"type":"Length","min":5,"max":100},{"type":"Regex","pattern":"/^\d+$/"}]
     *
     * @return list<ConstraintDef>
     */
    private function manualConstraints(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $constraints = [];
        foreach ($decoded as $def) {
            if (!is_array($def) || !isset($def['type']) || !is_string($def['type'])) {
                continue;
            }

            $shortName = $def['type'];
            if (!isset(self::ALLOWED[$shortName])) {
                continue; // silently ignore unknown / disallowed constraints
            }

            $options = $def;
            unset($options['type']);

            $constraints[] = ['class' => self::ALLOWED[$shortName], 'options' => $options];
        }

        return $constraints;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a copy of $fieldValues with the form_builder_ prefix stripped from keys.
     *
     * @param array<string, mixed> $fieldValues
     * @return array<string, mixed>
     */
    private function normalize(array $fieldValues): array
    {
        $result = [];
        foreach ($fieldValues as $key => $value) {
            $normalizedKey = str_starts_with($key, 'form_builder_') ? substr($key, 13) : $key;
            $result[$normalizedKey] = $value;
        }

        return $result;
    }

    /**
     * Instantiates a cached constraint definition into a live Symfony constraint object.
     *
     * @param ConstraintDef $def
     */
    public static function instantiate(array $def): Constraint
    {
        $class = $def['class'];
        $options = $def['options'];

        return empty($options) ? new $class() : new $class($options);
    }
}
