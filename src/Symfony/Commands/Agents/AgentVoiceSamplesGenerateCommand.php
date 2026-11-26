<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Voice\Samples\AgentVoiceSamplesService;
use DDD\Domain\AI\Services\Voice\Samples\LiveVoiceSampleRecording;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Makes the voice samples of the voice picker ({@see AgentVoiceSamplesService}): for every configured voice and
 * language whose current sample (model, sentence, format) is missing, one short live session speaks the sentence and the
 * WAV goes into the object store. Existing current samples are kept, so a second run costs nothing; after a model or
 * sentence change every sample is made again. One failing sample never stops the others; the command then ends with
 * a failure and names each.
 *
 *   php bin/console app:agent:voice-samples:generate
 *   php bin/console app:agent:voice-samples:generate --voice=flitz --language=de --force
 *   php bin/console app:agent:voice-samples:generate --list
 *
 * Each sample is one GPT-Live session of about 8 seconds, priced and booked by the catalog of the live model
 * (the session worker's LiveVoiceSampleSynthesizer::recordSessionSeconds()). Run it once after a deploy that changes the
 * model or a sentence. Speaking needs the WebSocket library of the voice session worker (ratchet/pawl, react/event-loop):
 * without it the command answers that the library is missing; `--list` works without it.
 */
#[AsCommand(
    name: 'app:agent:voice-samples:generate',
    description: 'Make the missing voice samples of the voice picker (one per voice and language) with the live model of the calls.',
)]
class AgentVoiceSamplesGenerateCommand extends AgentConsoleCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('voice', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these voices (repeatable)');
        $this->addOption('language', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these languages (repeatable)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Speak and store again although the current sample exists');
        $this->addOption('list', null, InputOption::VALUE_NONE, 'Only list the samples and whether each exists; speak nothing');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var AgentVoiceSamplesService $agentVoiceSamplesService */
        $agentVoiceSamplesService = DDDService::instance()->getService(AgentVoiceSamplesService::class);

        $voiceIds = $this->selectValues($agentVoiceSamplesService->getSampleVoiceIds(), (array)$input->getOption('voice'));
        $languageCodes = $this->selectValues($agentVoiceSamplesService->getSampleLanguageCodes(), (array)$input->getOption('language'));
        if ($voiceIds === [] || $languageCodes === []) {
            $symfonyStyle->error('No configured voice or language matches (AI.agentVoice.samples)');
            return Command::INVALID;
        }

        if (!$input->getOption('list') && !$agentVoiceSamplesService->canGenerateSamplesHere()) {
            $symfonyStyle->error(AgentVoiceSamplesService::LIBRARY_MISSING_MESSAGE);
            return Command::FAILURE;
        }

        $basicObjectStorage = $agentVoiceSamplesService->getBasicObjectStorage();
        if ($input->getOption('list')) {
            $rows = [];
            foreach ($voiceIds as $voiceId) {
                foreach ($languageCodes as $languageCode) {
                    $sampleStorageKey = $agentVoiceSamplesService->buildSampleStorageKey($voiceId, $languageCode);
                    $rows[] = [$voiceId, $languageCode, $sampleStorageKey, $basicObjectStorage->objectExists($sampleStorageKey) ? 'yes' : 'no'];
                }
            }
            $symfonyStyle->table(['voice', 'language', 'key', 'exists'], $rows);
            return Command::SUCCESS;
        }

        $force = (bool)$input->getOption('force');
        $countsByResult = [AgentVoiceSamplesService::GENERATION_CREATED => 0, AgentVoiceSamplesService::GENERATION_KEPT => 0];
        $failures = [];
        foreach ($voiceIds as $voiceId) {
            foreach ($languageCodes as $languageCode) {
                $startedAt = microtime(true);
                try {
                    $generationResult = $agentVoiceSamplesService->generateSample($voiceId, $languageCode, $force);
                } catch (Throwable $throwable) {
                    $failures[] = "{$voiceId}/{$languageCode}: {$throwable->getMessage()}";
                    $symfonyStyle->writeln("<error>{$voiceId}/{$languageCode} failed</error> {$throwable->getMessage()}");
                    continue;
                }
                $countsByResult[$generationResult]++;
                $liveVoiceSampleRecording = $generationResult === AgentVoiceSamplesService::GENERATION_CREATED
                    ? $agentVoiceSamplesService->lastLiveVoiceSampleRecording
                    : null;
                $symfonyStyle->writeln(sprintf(
                    '%s/%s %s (%.1f s)%s',
                    $voiceId,
                    $languageCode,
                    $generationResult,
                    microtime(true) - $startedAt,
                    $liveVoiceSampleRecording !== null
                        ? sprintf(' audio %.1f s, said: %s', $liveVoiceSampleRecording->getAudioSeconds(LiveVoiceSampleRecording::SAMPLE_RATE), trim($liveVoiceSampleRecording->transcript))
                        : ''
                ));
                if ($liveVoiceSampleRecording !== null && $output->isVerbose()) {
                    $symfonyStyle->writeln('  events: ' . (string)json_encode($liveVoiceSampleRecording->eventTypeCounts));
                }
            }
        }
        $symfonyStyle->definitionList(
            ['created' => (string)$countsByResult[AgentVoiceSamplesService::GENERATION_CREATED]],
            ['kept' => (string)$countsByResult[AgentVoiceSamplesService::GENERATION_KEPT]],
            ['failed' => (string)count($failures)],
        );
        if ($failures !== []) {
            $symfonyStyle->error($failures);
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * The configured values, narrowed to the requested ones when any are given.
     *
     * @param string[] $configuredValues
     * @param string[] $requestedValues
     * @return string[]
     */
    protected function selectValues(array $configuredValues, array $requestedValues): array
    {
        $requestedValues = array_values(array_filter(array_map('trim', $requestedValues), static fn (string $value): bool => $value !== ''));
        return $requestedValues === [] ? $configuredValues : array_values(array_intersect($configuredValues, $requestedValues));
    }
}
