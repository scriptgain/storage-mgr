<?php

namespace App\Services\S3;

/**
 * Evaluates AWS-style IAM policy documents against an (action, resource) pair.
 *
 * Rules, in the order they matter:
 *   1. An explicit Deny always wins, whatever else matches.
 *   2. Otherwise access needs an explicit Allow.
 *   3. With no matching statement at all, the answer is no.
 *
 * Deny-by-default is deliberate: the failure mode of a permissive evaluator is
 * silent data exposure, which nothing downstream would report. A too-strict
 * evaluator produces a loud AccessDenied instead, which gets noticed and fixed.
 */
class PolicyEvaluator
{
    /**
     * @param  array|string|null  $document  IAM policy (decoded array or JSON)
     * @param  string  $action  e.g. "s3:GetObject"
     * @param  string  $resource  e.g. "arn:aws:s3:::bucket/key"
     */
    public function allows($document, string $action, string $resource): bool
    {
        $statements = $this->statements($document);
        if ($statements === []) {
            return false;
        }

        $allowed = false;

        foreach ($statements as $statement) {
            if (! $this->matchesAction($statement, $action) || ! $this->matchesResource($statement, $resource)) {
                continue;
            }

            if (strtolower((string) ($statement['Effect'] ?? '')) === 'deny') {
                return false;  // explicit deny is final
            }

            if (strtolower((string) ($statement['Effect'] ?? '')) === 'allow') {
                $allowed = true;
            }
        }

        return $allowed;
    }

    /** Normalise a policy document into a list of statements. */
    private function statements($document): array
    {
        if (is_string($document)) {
            $document = json_decode($document, true);
        }
        if (! is_array($document)) {
            return [];
        }

        $statements = $document['Statement'] ?? $document;
        if (! is_array($statements)) {
            return [];
        }

        // A single statement object is legal, as is a list of them.
        return isset($statements['Effect']) ? [$statements] : array_values(array_filter($statements, 'is_array'));
    }

    private function matchesAction(array $statement, string $action): bool
    {
        if (isset($statement['NotAction'])) {
            return ! $this->anyMatch((array) $statement['NotAction'], $action, true);
        }

        return $this->anyMatch((array) ($statement['Action'] ?? []), $action, true);
    }

    private function matchesResource(array $statement, string $resource): bool
    {
        if (isset($statement['NotResource'])) {
            return ! $this->anyMatch((array) $statement['NotResource'], $resource, false);
        }

        // A statement without Resource applies to everything it otherwise matches.
        if (! isset($statement['Resource'])) {
            return true;
        }

        return $this->anyMatch((array) $statement['Resource'], $resource, false);
    }

    /** @param array<int,string> $patterns */
    private function anyMatch(array $patterns, string $value, bool $caseInsensitive): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->wildcardMatch((string) $pattern, $value, $caseInsensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IAM wildcards: "*" spans any characters, "?" exactly one. Everything else
     * is quoted, so a pattern can never be read as a regular expression.
     */
    private function wildcardMatch(string $pattern, string $value, bool $caseInsensitive): bool
    {
        $regex = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/'
            .($caseInsensitive ? 'i' : '');

        return (bool) preg_match($regex, $value);
    }
}
