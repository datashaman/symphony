<?php

use App\Logging\StructuredFormatter;
use Monolog\Level;
use Monolog\LogRecord;

function makeLogRecord(string $message, Level $level = Level::Info, array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2025-01-15T15:00:00+00:00'),
        channel: 'symphony',
        level: $level,
        message: $message,
        context: $context,
    );
}

it('formats basic log entry as key=value pairs', function () {
    $formatter = new StructuredFormatter;
    $record = makeLogRecord('issue dispatched');
    $output = $formatter->format($record);

    expect($output)->toContain('timestamp=2025-01-15T15:00:00+00:00');
    expect($output)->toContain('level=info');
    expect($output)->toContain('message="issue dispatched"');
});

it('includes context fields when available', function () {
    $formatter = new StructuredFormatter;
    $record = makeLogRecord('processing', context: [
        'issue_id' => '42',
        'issue_identifier' => 'symphony#42',
        'session_id' => 'sess_abc',
    ]);
    $output = $formatter->format($record);

    expect($output)->toContain('issue_id=42');
    expect($output)->toContain('issue_identifier=symphony#42');
    expect($output)->toContain('session_id=sess_abc');
});

it('omits missing optional fields', function () {
    $formatter = new StructuredFormatter;
    $record = makeLogRecord('startup', context: [
        'issue_id' => '42',
    ]);
    $output = $formatter->format($record);

    expect($output)->toContain('issue_id=42');
    expect($output)->not->toContain('session_id');
    expect($output)->not->toContain('issue_identifier');
});

it('redacts sensitive values', function () {
    $formatter = new StructuredFormatter;
    $record = makeLogRecord('config loaded', context: [
        'api_key' => 'ghp_secret123',
        'api_token' => 'tok_abc',
        'normal_field' => 'visible',
    ]);
    $output = $formatter->format($record);

    expect($output)->not->toContain('ghp_secret123');
    expect($output)->not->toContain('tok_abc');
    expect($output)->toContain('[REDACTED]');
    expect($output)->toContain('normal_field=visible');
});

it('handles different log levels', function () {
    $formatter = new StructuredFormatter;

    $warning = $formatter->format(makeLogRecord('something bad', Level::Warning));
    expect($warning)->toContain('level=warning');

    $error = $formatter->format(makeLogRecord('very bad', Level::Error));
    expect($error)->toContain('level=error');
});
