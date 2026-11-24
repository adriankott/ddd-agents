<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeResult;
use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeResults;
use DDD\Domain\AI\Services\Eval\Probes\AgentReasoningReplayProbeService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Live probe of reasoning replay against OpenRouter (the evidence for the Anthropic reasoning-replay work): a small
 * synthetic tool conversation in memory, sent with the agent's composers, the model's catalog provider filters and
 * the agent's OpenRouter key. Changes nothing in the agent and persists nothing; costs real tokens (a few cents per
 * scenario on Claude Opus 5.5).
 *
 * Exit codes: 0 every scenario as expected (PASS or FINDING), 1 a scenario FAILed (the probe's own path broke, or a
 * clean replay was refused), 2 invalid options.
 */
#[AsCommand(
    name: 'app:ai-agent:probe-reasoning-replay',
    description: 'Live probe: does a model accept its own reasoning sent back (clean, after history/tool/system edits, after a model switch), a forced tool choice, and append-only skill loading on the Responses API? Real model calls with the agent\'s OpenRouter key; persists nothing'
)]
class AgentProbeReasoningReplayCommand extends AgentConsoleCommand
{
    public const string SCENARIO_ALL = 'all';

    public const int EXIT_INVALID_OPTIONS = 2;

    protected function configure(): void
    {
        $this
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'The catalog model (e.g. ANTHROPIC.CLAUDE_OPUS_5_5, MOONSHOT.KIMI_K3_1_PRO)')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'One or more of ' . implode(', ', AgentReasoningReplayProbeService::SCENARIOS) . ' (comma-separated), or "all" (every scenario the wire allows; model-switch only with --switch-model)', AgentReasoningReplayProbeService::SCENARIO_CLEAN)
            ->addOption('wire', null, InputOption::VALUE_REQUIRED, 'chatCompletions, responses or messages (responses for a non-OpenAI model bypasses the agent\'s runtime refusal, in this probe only; messages = the Anthropic Messages API with its own task, drop_block on every call — use --effort high so the steps think)', RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS)
            ->addOption('switch-model', null, InputOption::VALUE_REQUIRED, 'The model of call 2 in the model-switch scenario')
            ->addOption('drop-block', null, InputOption::VALUE_NONE, 'Replaying calls ask the provider to drop mismatching thinking blocks (thinking.block_binding.prefix_mismatch_behavior = drop_block) instead of refusing')
            ->addOption('effort', null, InputOption::VALUE_REQUIRED, 'Reasoning effort of every call', 'medium')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Total timeout of one call in seconds', '120')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(1800);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $modelName = (string)$input->getOption('model');
        $wireApi = (string)$input->getOption('wire');
        $switchModelName = $input->getOption('switch-model') !== null ? (string)$input->getOption('switch-model') : null;
        if ($modelName === '') {
            $symfonyStyle->error('--model is required');
            return self::EXIT_INVALID_OPTIONS;
        }
        $scenarios = $this->resolveScenarios((string)$input->getOption('scenario'), $wireApi, $switchModelName);
        /** @var AgentReasoningReplayProbeService $agentReasoningReplayProbeService */
        $agentReasoningReplayProbeService = DDDService::instance()->getService(AgentReasoningReplayProbeService::class);
        $reasoningReplayProbeResults = new ReasoningReplayProbeResults();
        try {
            foreach ($scenarios as $scenario) {
                $reasoningReplayProbeResult = $agentReasoningReplayProbeService->runScenario(
                    $modelName,
                    $scenario,
                    $wireApi,
                    (bool)$input->getOption('drop-block'),
                    $switchModelName,
                    (string)$input->getOption('effort'),
                    max(10, (int)$input->getOption('timeout'))
                );
                $reasoningReplayProbeResults->add($reasoningReplayProbeResult);
            }
        } catch (BadRequestException $badRequestException) {
            $symfonyStyle->error($badRequestException->getMessage());
            return self::EXIT_INVALID_OPTIONS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error('The probe stopped: ' . $throwable->getMessage());
            return Command::FAILURE;
        }
        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode($reasoningReplayProbeResults->toObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTables($reasoningReplayProbeResults, $output, $symfonyStyle);
        }
        return $reasoningReplayProbeResults->hasFailure() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The scenarios to run: the comma-separated list, or for "all" every scenario the wire allows (append-only on
     * Responses and Messages, capped-cut and current-turn-strip on Messages, model-switch only with a switch model).
     *
     * @return string[]
     */
    protected function resolveScenarios(string $scenarioOption, string $wireApi, ?string $switchModelName): array
    {
        if ($scenarioOption !== self::SCENARIO_ALL) {
            return array_values(array_filter(array_map('trim', explode(',', $scenarioOption)), 'strlen'));
        }
        $scenarios = [];
        foreach (AgentReasoningReplayProbeService::SCENARIOS as $scenario) {
            if ($scenario === AgentReasoningReplayProbeService::SCENARIO_APPEND_ONLY && !in_array($wireApi, AgentReasoningReplayProbeService::APPEND_ONLY_WIRE_APIS, true)) {
                continue;
            }
            if (in_array($scenario, AgentReasoningReplayProbeService::MESSAGES_ONLY_SCENARIOS, true) && $wireApi !== RunSettings::AGENT_WIRE_API_MESSAGES) {
                continue;
            }
            if ($scenario === AgentReasoningReplayProbeService::SCENARIO_MODEL_SWITCH && ($switchModelName === null || $switchModelName === '')) {
                continue;
            }
            $scenarios[] = $scenario;
        }
        return $scenarios;
    }

    protected function renderTables(ReasoningReplayProbeResults $reasoningReplayProbeResults, OutputInterface $output, SymfonyStyle $symfonyStyle): void
    {
        foreach ($reasoningReplayProbeResults->getElements() as $reasoningReplayProbeResult) {
            $symfonyStyle->section(sprintf(
                '%s · %s · %s%s%s',
                $reasoningReplayProbeResult->scenario,
                $reasoningReplayProbeResult->wireApi,
                $reasoningReplayProbeResult->modelName,
                $reasoningReplayProbeResult->switchModelName !== null ? ' → ' . $reasoningReplayProbeResult->switchModelName : '',
                $reasoningReplayProbeResult->requestsDropBlock ? ' · drop_block' : ''
            ));
            if ($reasoningReplayProbeResult->bypassesResponsesRejection) {
                $symfonyStyle->warning('Responses API for a non-OpenAI model: the agent refuses this at runtime (REJECTION_RESPONSES_NEEDS_OPENAI_MODEL); this probe bypasses the refusal for itself only.');
            }
            $table = new Table($output);
            $table->setHeaders(['call', 'model', 'HTTP', 'ok', 'provider', 'tool choice', 'tools called', 'reasoning back', 'signed', 'replayed', 'dropped', 'thinking tok', 'input', 'cached', 'cache share', 'cache write', 'cost $', 'ms']);
            foreach ($reasoningReplayProbeResult->calls->getElements() as $reasoningReplayProbeCall) {
                $table->addRow([
                    $reasoningReplayProbeCall->label,
                    $reasoningReplayProbeCall->modelName,
                    $reasoningReplayProbeCall->httpStatus ?? '-',
                    $reasoningReplayProbeCall->isAccepted ? 'yes' : 'NO',
                    $reasoningReplayProbeCall->provider ?? '-',
                    $reasoningReplayProbeCall->sentToolChoice ?? '-',
                    implode(', ', $reasoningReplayProbeCall->calledToolNames) ?: '-',
                    $reasoningReplayProbeCall->reasoningItemCount . ($reasoningReplayProbeCall->reasoningItemTypes !== [] ? ' (' . implode(', ', array_unique($reasoningReplayProbeCall->reasoningItemTypes)) . ')' : ''),
                    $reasoningReplayProbeCall->hasSignedReasoning ? 'yes' : 'no',
                    $reasoningReplayProbeCall->replayedReasoningItemCount,
                    $reasoningReplayProbeCall->droppedThinkingBlockCount,
                    $reasoningReplayProbeCall->reasoningTokens ?? '-',
                    $reasoningReplayProbeCall->inputTokens ?? '-',
                    $reasoningReplayProbeCall->cachedInputTokens ?? '-',
                    $reasoningReplayProbeCall->getCacheShare() !== null ? number_format($reasoningReplayProbeCall->getCacheShare() * 100, 1) . ' %' : '-',
                    $reasoningReplayProbeCall->cacheWriteTokens ?? '-',
                    $reasoningReplayProbeCall->costInUsd !== null ? number_format($reasoningReplayProbeCall->costInUsd, 4) : '-',
                    $reasoningReplayProbeCall->durationMs,
                ]);
            }
            $table->render();
            foreach ($reasoningReplayProbeResult->calls->getElements() as $reasoningReplayProbeCall) {
                if ($reasoningReplayProbeCall->errorText !== null) {
                    $output->writeln('  <error>' . $reasoningReplayProbeCall->label . '</error> ' . $reasoningReplayProbeCall->errorText);
                }
                if ($reasoningReplayProbeCall->inputTransformationsJson !== null) {
                    $output->writeln('  input_transformations of ' . $reasoningReplayProbeCall->label . ': ' . $reasoningReplayProbeCall->inputTransformationsJson);
                }
            }
            $output->writeln('  expected: ' . $reasoningReplayProbeResult->expectation);
            $verdictStyle = match ($reasoningReplayProbeResult->verdict) {
                ReasoningReplayProbeResult::VERDICT_PASS => 'info',
                ReasoningReplayProbeResult::VERDICT_FINDING => 'comment',
                default => 'error',
            };
            $output->writeln(sprintf('  verdict: <%s>%s</%s> — %s', $verdictStyle, $reasoningReplayProbeResult->verdict, $verdictStyle, $reasoningReplayProbeResult->verdictReason));
        }
        $costInUsd = 0.0;
        foreach ($reasoningReplayProbeResults->getElements() as $reasoningReplayProbeResult) {
            $costInUsd += $reasoningReplayProbeResult->getCostInUsd() ?? 0.0;
        }
        $symfonyStyle->newLine();
        $symfonyStyle->text(sprintf('Total cost reported by OpenRouter: $%s (not booked on any account).', number_format($costInUsd, 4)));
    }
}
