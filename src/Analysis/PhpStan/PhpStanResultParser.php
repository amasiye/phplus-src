<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\PhpStan;

use Amasiye\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;

final class PhpStanResultParser
{
    public function parse(string $json): PhpStanParsedResult
    {
        if (trim($json) === '') {
            throw new PhpStanExecutionException('The static-analysis backend returned an empty result.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PhpStanExecutionException('The static-analysis backend returned malformed JSON.', previous: $exception);
        }

        if (!is_array($decoded) || !isset($decoded['files'], $decoded['errors']) || !is_array($decoded['files']) || !is_array($decoded['errors'])) {
            throw new PhpStanExecutionException('The static-analysis backend returned an unexpected result shape.');
        }

        $findings = [];

        foreach ($decoded['files'] as $path => $file) {
            if (!is_string($path) || !is_array($file) || !isset($file['messages']) || !is_array($file['messages'])) {
                throw new PhpStanExecutionException('The static-analysis backend returned an invalid file result.');
            }

            foreach ($file['messages'] as $message) {
                if (
                    !is_array($message)
                    || !isset($message['message'], $message['line'], $message['ignorable'])
                    || !is_string($message['message'])
                    || !is_int($message['line'])
                    || !is_bool($message['ignorable'])
                    || (isset($message['identifier']) && !is_string($message['identifier']))
                ) {
                    throw new PhpStanExecutionException('The static-analysis backend returned an invalid finding.');
                }

                $findings[] = new PhpStanFinding(
                    $path,
                    $message['message'],
                    max(1, $message['line']),
                    $message['identifier'] ?? null,
                    $message['ignorable'],
                );
            }
        }

        $globalErrors = [];

        foreach ($decoded['errors'] as $error) {
            if (!is_string($error)) {
                throw new PhpStanExecutionException('The static-analysis backend returned an invalid global error.');
            }

            $globalErrors[] = $error;
        }

        return new PhpStanParsedResult($findings, $globalErrors);
    }
}
