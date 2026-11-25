<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * The decision model behind the Argus transport: the network handler of an `ArgusTransportRecording` in record mode, so
 * a decision call of a site runs the real Argus path (the payload build with its gates, the settled batch, the answer
 * readers) and only the HTTP answer is scripted. A request is labelled by what it asks — `ownerMessageInjection`,
 * `toolResultInjection`, `subSkill:<loaded skill>` (a sub-skill pass), else `primary` — and answered per label:
 * - `usable`: HTTP 200 with an answer for every question (a scripted value, else the default below);
 * - `unparsable`: HTTP 200 with an empty `answers` object;
 * - `http503`: HTTP 503 (a status of the transport list);
 * - `http400`: HTTP 400 (our own request is wrong, never transport);
 * - `timeout`: a rejected promise carrying a cURL timeout (what a settled batch sees on a timed-out child).
 */
class DecisionModelNetworkDouble
{
    public const string MODE_USABLE = 'usable';

    public const string MODE_UNPARSABLE = 'unparsable';

    public const string MODE_HTTP_503 = 'http503';

    public const string MODE_HTTP_400 = 'http400';

    public const string MODE_TIMEOUT = 'timeout';

    public const string ANSWERING_MODEL_VERSION = 'typesafe/jev-test';

    public string $defaultMode = self::MODE_USABLE;

    /** @var array<string, string> label → mode, before {@see $defaultMode} */
    public array $modeByLabel = [];

    /**
     * @var array<string, float|string> question name → the scripted answer: a float for a `noul` question (P(true)), a
     *      string for a `choice` question (the pick, reported with 0.9 and the remaining 0.1 spread evenly)
     */
    public array $answersByQuestion = [];

    /** @var array<string, array<string, float|string>> label → question name → answer, before {@see $answersByQuestion} */
    public array $answersByLabel = [];

    /** P(true) of every `noul` question without a scripted answer. */
    public float $defaultNoulProbability = 0.2;

    /** @var string[] question names a usable answer leaves out */
    public array $omittedQuestionNames = [];

    /** @var array<int, array{label: string, questionNames: string[]}> every request, in send order */
    public array $sentRequests = [];

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $decodedBody = json_decode((string)$request->getBody(), true);
        $questions = is_array($decodedBody['questions'] ?? null) ? $decodedBody['questions'] : [];
        $state = is_array($decodedBody['state'] ?? null) ? $decodedBody['state'] : [];
        $label = self::labelOf($questions, $state);
        $this->sentRequests[] = ['label' => $label, 'questionNames' => array_keys($questions)];
        $mode = $this->modeByLabel[$label] ?? $this->defaultMode;
        $this->worldLog->record('request ' . $label . ' ' . $mode);
        return match ($mode) {
            self::MODE_TIMEOUT => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 2000 milliseconds with 0 bytes received', $request)),
            self::MODE_HTTP_503 => Create::promiseFor(new Response(503, ['Content-Type' => 'application/json'], '{"error":{"message":"upstream unavailable","code":503}}')),
            self::MODE_HTTP_400 => Create::promiseFor(new Response(400, ['Content-Type' => 'application/json'], '{"error":{"message":"invalid request","code":400}}')),
            self::MODE_UNPARSABLE => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"model":"' . self::ANSWERING_MODEL_VERSION . '","answers":{}}')),
            default => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string)json_encode([
                'model' => self::ANSWERING_MODEL_VERSION,
                'answers' => $this->buildAnswers($questions, $label),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))),
        };
    }

    /**
     * @param array<string, mixed> $questions
     * @param array<string, mixed> $state
     */
    public static function labelOf(array $questions, array $state): string
    {
        if (array_key_exists('gate_passes_message', $questions)) {
            return 'ownerMessageInjection';
        }
        if (array_key_exists('injection_present', $questions)) {
            return 'toolResultInjection';
        }
        if (is_array($state['loadedSkill'] ?? null)) {
            return 'subSkill:' . (string)($state['loadedSkill']['name'] ?? '');
        }
        return 'primary';
    }

    /** @return string[] the labels of the requests sent, in send order */
    public function sentLabels(): array
    {
        return array_map(static fn (array $sentRequest): string => $sentRequest['label'], $this->sentRequests);
    }

    /**
     * @param array<string, mixed> $questions
     * @return array<string, array<string, mixed>>
     */
    protected function buildAnswers(array $questions, string $label): array
    {
        $answers = [];
        foreach ($questions as $questionName => $question) {
            if (in_array($questionName, $this->omittedQuestionNames, true)) {
                continue;
            }
            $scriptedAnswer = $this->answersByLabel[$label][$questionName] ?? $this->answersByQuestion[$questionName] ?? null;
            if (($question['type'] ?? null) === 'choice') {
                $criteriaKeys = array_map('strtolower', array_map('strval', array_keys((array)($question['criteria'] ?? []))));
                $answers[$questionName] = $this->buildChoiceAnswer($criteriaKeys, is_string($scriptedAnswer) ? $scriptedAnswer : null);
                continue;
            }
            $answers[$questionName] = ['type' => 'noul', 'noul' => is_float($scriptedAnswer) ? $scriptedAnswer : $this->defaultNoulProbability];
        }
        return $answers;
    }

    /**
     * @param string[] $criteriaKeys
     * @return array<string, mixed>
     */
    protected function buildChoiceAnswer(array $criteriaKeys, ?string $scriptedChoice): array
    {
        $choice = $scriptedChoice ?? (in_array('none', $criteriaKeys, true) ? 'none' : (string)($criteriaKeys[0] ?? 'none'));
        $otherKeys = array_values(array_diff($criteriaKeys, [$choice]));
        $probabilities = [$choice => 0.9];
        foreach ($otherKeys as $otherKey) {
            $probabilities[$otherKey] = round(0.1 / count($otherKeys), 4);
        }
        return ['type' => 'choice', 'choice' => $choice, 'confidence' => 0.9, 'probabilities' => $probabilities];
    }
}
