<?php

/**
 * Turns the failures in a PHPUnit JUnit log into GitHub Actions error
 * annotations, so they show on the run page (and in the checks API) without
 * opening the raw job log.
 *
 * Usage: php .github/scripts/annotate-junit.php junit.xml
 */
const MAX_ANNOTATIONS = 40;
const MAX_MESSAGE_LENGTH = 3000;

/** Escapes the value of a workflow command message. */
function escapeData(string $value): string
{
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}

/** Escapes a workflow command property such as file= or title=. */
function escapeProperty(string $value): string
{
    return str_replace([':', ','], ['%3A', '%2C'], escapeData($value));
}

$path = $argv[1] ?? 'junit.xml';

if (! is_file($path)) {
    echo '::error title=Tests did not finish::No JUnit log was written, see the job log.'."\n";
    exit(0);
}

$xml = simplexml_load_file($path);

if ($xml === false) {
    echo '::error title=Unreadable JUnit log::'.escapeData("Could not parse {$path}.")."\n";
    exit(0);
}

$root = rtrim(str_replace('\\', '/', (string) getcwd()), '/').'/';
$problems = $xml->xpath('//testcase[failure or error]') ?: [];
$shown = 0;

foreach ($problems as $case) {
    if ($shown >= MAX_ANNOTATIONS) {
        break;
    }

    $detail = $case->xpath('failure|error')[0] ?? null;
    $message = trim((string) $detail);

    // Keep the start and the end: PHPUnit puts the useful part (which assertion
    // failed, the file and line) last, after a possibly huge dump of the response.
    if (strlen($message) > MAX_MESSAGE_LENGTH) {
        $head = (int) (MAX_MESSAGE_LENGTH * 0.2);
        $message = substr($message, 0, $head)."\n[...]\n".substr($message, -(MAX_MESSAGE_LENGTH - $head));
    }

    $file = str_replace('\\', '/', (string) $case['file']);
    $file = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    $title = ((string) $case['class'] !== '' ? $case['class'].'::' : '').$case['name'];

    $properties = [];
    if ($file !== '') {
        $properties[] = 'file='.escapeProperty($file);
    }
    if ((int) $case['line'] > 0) {
        $properties[] = 'line='.(int) $case['line'];
    }
    $properties[] = 'title='.escapeProperty($title);

    echo '::error '.implode(',', $properties).'::'.escapeData($message)."\n";
    $shown++;
}

$total = count($problems);

if ($total > $shown) {
    echo '::warning title=More failures::'.escapeData(($total - $shown).' more failing tests are not annotated, see the job log.')."\n";
}

echo "{$total} failing test(s) found in {$path}.\n";
