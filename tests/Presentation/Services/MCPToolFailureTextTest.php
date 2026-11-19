<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Validation\ValidationError;
use DDD\Infrastructure\Validation\ValidationErrors;
use DDD\Infrastructure\Validation\ValidationResult;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\UnitTestCase;
use PDOException;
use RuntimeException;
use TypeError;

/**
 * What a failed tool call answers with: a client error of the framework keeps its message (it is written for the
 * caller), every other throwable is logged and answered with the issue id only, for a call over HTTP and for the
 * in-process agent loop alike.
 */
class MCPToolFailureTextTest extends UnitTestCase
{
    public function testAClientErrorOfTheFrameworkKeepsItsMessage(): void
    {
        $executor = new IssueIdStubbedToolExecutor();

        self::assertSame(
            "Execution failed for Tool 'updateNote': Property \"title\" is missing",
            $executor->callBuildToolFailureText('updateNote', new BadRequestException('Property "title" is missing'))
        );
        self::assertSame(
            "Execution failed for Tool 'getNote': Note 7 not found.",
            $executor->callBuildToolFailureText('getNote', new NotFoundException('Note 7 not found.'))
        );
        self::assertSame(
            "Execution failed for Tool 'getNote': No access.",
            $executor->callBuildToolFailureText('getNote', new ForbiddenException('No access.'), new AgentToolCallProvenance())
        );
        self::assertSame([], $executor->loggedMessages, 'a refusal written for the caller is no issue');
    }

    public function testAValidationFailureNamesItsViolations(): void
    {
        $validationError = new ValidationError('.');
        $titleValidationResult = new ValidationResult('title', 'This value should not be blank.', '', 'Note');
        $validationError->add($titleValidationResult);
        $nestedValidationError = new ValidationError('.address.');
        $postalCodeValidationResult = new ValidationResult('postalCode', "This value is too long.\nIt should have 10 characters or less.", '123456789012345', 'Address');
        $nestedValidationError->add($postalCodeValidationResult);
        $validationErrors = new ValidationErrors();
        $validationErrors->add($validationError);
        $validationErrors->add($nestedValidationError);
        $badRequestException = new BadRequestException('Request contains invalid data');
        $badRequestException->validationErrors = $validationErrors;

        self::assertSame(
            "Execution failed for Tool 'updateNote': Request contains invalid data\nViolations:\n"
            . "- title: This value should not be blank.\n"
            . '- address.postalCode: This value is too long. It should have 10 characters or less.',
            (new IssueIdStubbedToolExecutor())->callBuildToolFailureText('updateNote', $badRequestException)
        );
    }

    public function testTheViolationsAreCappedAndTheReceivedValueIsLeftOut(): void
    {
        $validationError = new ValidationError('.');
        for ($violationNumber = 1; $violationNumber <= 13; $violationNumber++) {
            $fieldValidationResult = new ValidationResult('field' . $violationNumber, str_repeat('x', 300), 'SECRET-VALUE', 'Note');
            $validationError->add($fieldValidationResult);
        }
        $validationErrors = new ValidationErrors();
        $validationErrors->add($validationError);
        $badRequestException = new BadRequestException('Request contains invalid data');
        $badRequestException->validationErrors = $validationErrors;

        $failureText = (new IssueIdStubbedToolExecutor())->callBuildToolFailureText('updateNote', $badRequestException);

        self::assertStringNotContainsString('SECRET-VALUE', $failureText);
        self::assertSame(MCPToolExecutorService::MAX_VALIDATION_VIOLATIONS_IN_FAILURE_TEXT, substr_count($failureText, "\n- field"));
        self::assertStringEndsWith("\n- … and 3 more", $failureText);
        self::assertStringContainsString('- field1: ' . str_repeat('x', MCPToolExecutorService::MAX_VALIDATION_VIOLATION_MESSAGE_CHARS - 1) . "…\n", $failureText);
    }

    public function testAnyOtherThrowableIsLoggedAndAnsweredWithTheIssueIdOnly(): void
    {
        $internalFailures = [
            new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'secretColumn' in 'field list'"),
            new RuntimeException('cURL error 7: Failed to connect to internal-host.example port 8443'),
            new TypeError('Typed property Some\\Internal\\Entity::$name must not be accessed before initialization'),
            new InternalErrorException('Lock table /var/lib/data/locks is full'),
        ];
        foreach ([null, new AgentToolCallProvenance()] as $serverProvidedInput) {
            foreach ($internalFailures as $internalFailure) {
                $executor = new IssueIdStubbedToolExecutor();
                $text = $executor->callBuildToolFailureText('saveNote', $internalFailure, $serverProvidedInput);

                self::assertSame(
                    "Execution failed for Tool 'saveNote': an internal error occurred (issue id a1b2c3). "
                    . 'The error was logged; retrying with the same arguments will most likely fail again.',
                    $text
                );
                self::assertSame([$internalFailure->getMessage()], $executor->loggedMessages);
            }
        }
    }

    public function testTheAnswerNamesNoIssueIdWhenTheLogHasNone(): void
    {
        $executor = new IssueIdStubbedToolExecutor();
        $executor->stubbedIssueId = null;

        self::assertSame(
            "Execution failed for Tool 'saveNote': an internal error occurred. "
            . 'The error was logged; retrying with the same arguments will most likely fail again.',
            $executor->callBuildToolFailureText('saveNote', new RuntimeException('boom'))
        );
    }
}
