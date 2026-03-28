<?php

declare(strict_types=1);

use Symfony\Component\Validator\Constraints;
use vardumper\IbexaFormBuilderBundle\Service\ConstraintResolver;

$resolver = new ConstraintResolver();

// resolve() — empty input

it('returns empty array for empty field values', function () use ($resolver) {
    expect($resolver->resolve([]))->toBe([]);
});

// Auto-constraints from field type

it('adds Email constraint for type=email', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'email']);

    expect($result)->toHaveCount(1)
        ->and($result[0]['class'])->toBe(Constraints\Email::class)
        ->and($result[0]['options']['mode'])->toBe('html5');
});

it('adds Url constraint for type=url', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'url']);

    expect($result)->toHaveCount(1)
        ->and($result[0]['class'])->toBe(Constraints\Url::class);
});

it('adds Date constraint for type=date', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'date']);

    expect($result[0]['class'])->toBe(Constraints\Date::class);
});

it('adds Time constraint for type=time', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'time']);

    expect($result[0]['class'])->toBe(Constraints\Time::class);
});

it('adds DateTime constraint for type=datetime-local', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'datetime-local']);

    expect($result[0]['class'])->toBe(Constraints\DateTime::class)
        ->and($result[0]['options']['format'])->toBe('Y-m-d\TH:i');
});

// Length constraint from minlength / maxlength

it('adds Length constraint when minlength is set', function () use ($resolver) {
    $result = $resolver->resolve(['minlength' => '3']);

    $length = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Length::class));
    expect($length)->toHaveCount(1)
        ->and($length[0]['options']['min'])->toBe(3);
});

it('adds Length constraint when maxlength is set', function () use ($resolver) {
    $result = $resolver->resolve(['maxlength' => '100']);

    $length = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Length::class));
    expect($length)->toHaveCount(1)
        ->and($length[0]['options']['max'])->toBe(100);
});

it('adds Length constraint with both min and max', function () use ($resolver) {
    $result = $resolver->resolve(['minlength' => '5', 'maxlength' => '50']);

    $length = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Length::class));
    expect($length[0]['options'])->toBe(['min' => 5, 'max' => 50]);
});

it('ignores empty minlength/maxlength strings', function () use ($resolver) {
    $result = $resolver->resolve(['minlength' => '', 'maxlength' => '']);

    expect(array_filter($result, fn ($r) => $r['class'] === Constraints\Length::class))->toBeEmpty();
});

// Range constraint from min / max on number/range input types

it('adds Range constraint for type=number with min and max', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'number', 'min' => '1', 'max' => '10']);

    $range = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Range::class));
    expect($range)->toHaveCount(1)
        ->and($range[0]['options'])->toBe(['min' => 1.0, 'max' => 10.0]);
});

it('adds Range constraint for type=range with only min', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'range', 'min' => '0']);

    $range = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Range::class));
    expect($range)->toHaveCount(1)
        ->and($range[0]['options'])->toHaveKey('min')
        ->and($range[0]['options'])->not->toHaveKey('max');
});

it('does not add Range constraint for non-numeric type', function () use ($resolver) {
    $result = $resolver->resolve(['type' => 'text', 'min' => '1', 'max' => '10']);

    expect(array_filter($result, fn ($r) => $r['class'] === Constraints\Range::class))->toBeEmpty();
});

// Regex constraint from pattern

it('adds Regex constraint when pattern is set', function () use ($resolver) {
    $result = $resolver->resolve(['pattern' => '/^\d+$/']);

    $regex = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Regex::class));
    expect($regex)->toHaveCount(1)
        ->and($regex[0]['options']['pattern'])->toBe('/^\d+$/');
});

it('wraps pattern in slashes when missing leading slash', function () use ($resolver) {
    $result = $resolver->resolve(['pattern' => '^[A-Z]']);

    $regex = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Regex::class));
    expect($regex[0]['options']['pattern'])->toStartWith('/');
});

// NotBlank from required

it('adds NotBlank constraint when required is truthy', function () use ($resolver) {
    $result = $resolver->resolve(['required' => '1']);

    $notBlank = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\NotBlank::class));
    expect($notBlank)->toHaveCount(1);
});

it('does not add NotBlank when required is falsy', function () use ($resolver) {
    $result = $resolver->resolve(['required' => '']);

    expect(array_filter($result, fn ($r) => $r['class'] === Constraints\NotBlank::class))->toBeEmpty();
});

// form_builder_ prefix normalization

it('strips form_builder_ prefix from field keys', function () use ($resolver) {
    $result = $resolver->resolve(['form_builder_type' => 'email']);

    expect($result)->toHaveCount(1)
        ->and($result[0]['class'])->toBe(Constraints\Email::class);
});

// Manual JSON constraints field

it('resolves a JSON Length constraint', function () use ($resolver) {
    $result = $resolver->resolve(['constraints' => '[{"type":"Length","min":5,"max":100}]']);

    $length = array_values(array_filter($result, fn ($r) => $r['class'] === Constraints\Length::class));
    expect($length)->toHaveCount(1)
        ->and($length[0]['options']['min'])->toBe(5)
        ->and($length[0]['options']['max'])->toBe(100);
});

it('resolves multiple manual constraints', function () use ($resolver) {
    $result = $resolver->resolve(['constraints' => '[{"type":"NotBlank"},{"type":"Email"}]']);

    $classes = array_column($result, 'class');
    expect(in_array(Constraints\NotBlank::class, $classes, true))->toBeTrue()
        ->and(in_array(Constraints\Email::class, $classes, true))->toBeTrue();
});

it('silently ignores unknown constraint types in JSON', function () use ($resolver) {
    $result = $resolver->resolve(['constraints' => '[{"type":"EvilConstraint"},{"type":"NotBlank"}]']);

    $classes = array_column($result, 'class');
    expect(in_array(Constraints\NotBlank::class, $classes, true))->toBeTrue()
        ->and($classes)->not->toContain('EvilConstraint');
});

it('returns empty array for invalid JSON', function () use ($resolver) {
    $result = $resolver->resolve(['constraints' => 'not json at all']);

    expect($result)->toBe([]);
});

it('returns empty array for empty constraints field', function () use ($resolver) {
    $result = $resolver->resolve(['constraints' => '']);

    expect($result)->toBe([]);
});

it('skips malformed constraint entries missing type key', function () use ($resolver) {
    $result = $resolver->resolve(['constraints' => '[{"min":5}]']);

    expect($result)->toBe([]);
});

// instantiate()

it('instantiate() creates constraint with no options', function () {
    $def = ['class' => Constraints\NotBlank::class, 'options' => []];
    $constraint = ConstraintResolver::instantiate($def);

    expect($constraint)->toBeInstanceOf(Constraints\NotBlank::class);
});

it('instantiate() creates constraint with options', function () {
    $def = ['class' => Constraints\Regex::class, 'options' => ['pattern' => '/^\d+$/']];
    $constraint = ConstraintResolver::instantiate($def);

    expect($constraint)->toBeInstanceOf(Constraints\Regex::class)
        ->and($constraint->pattern)->toBe('/^\d+$/');
});
