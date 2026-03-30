<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

class StructuredFormatter implements FormatterInterface
{
    private const REDACTED_KEYS = ['api_key', 'api_token', 'password', 'secret', 'token'];

    public function format(LogRecord $record): string
    {
        $parts = [
            'timestamp=' . $record->datetime->format('c'),
            'level=' . strtolower($record->level->name),
            'message=' . $this->quoteValue($record->message),
        ];

        // Add standard context fields
        $contextFields = ['issue_id', 'issue_identifier', 'session_id'];
        foreach ($contextFields as $field) {
            if (isset($record->context[$field]) && $record->context[$field] !== null) {
                $parts[] = "{$field}=" . $this->quoteValue((string) $record->context[$field]);
            }
        }

        // Add remaining context (excluding standard fields)
        foreach ($record->context as $key => $value) {
            if (in_array($key, $contextFields, true)) {
                continue;
            }

            $value = $this->redactIfSensitive($key, $value);
            $parts[] = "{$key}=" . $this->formatValue($value);
        }

        return implode(' ', $parts) . "\n";
    }

    public function formatBatch(array $records): string
    {
        $output = '';
        foreach ($records as $record) {
            $output .= $this->format($record);
        }

        return $output;
    }

    private function quoteValue(string $value): string
    {
        if (str_contains($value, ' ') || str_contains($value, '"') || str_contains($value, '=')) {
            return '"' . addslashes($value) . '"';
        }

        return $value;
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return $this->quoteValue($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_array($value)) {
            return $this->quoteValue(json_encode($value));
        }

        return (string) $value;
    }

    private function redactIfSensitive(string $key, mixed $value): mixed
    {
        foreach (self::REDACTED_KEYS as $sensitiveKey) {
            if (stripos($key, $sensitiveKey) !== false) {
                return '[REDACTED]';
            }
        }

        return $value;
    }
}
