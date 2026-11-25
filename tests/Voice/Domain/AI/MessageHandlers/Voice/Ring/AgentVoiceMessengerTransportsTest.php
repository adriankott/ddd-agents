<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\Ring;

use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceMessengerTransports;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceRingDeadlineMessage;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The transport list an application copies: every message class of the voice channel (a class below a `Voice\`
 * folder of the package's `src/`; the package's other messages are routed by `AgentMessengerTransports`) has its
 * transport in
 * `ROUTING_BY_MESSAGE_CLASS`, every routed transport is in `ALL`, and each handler consumes exactly the transport its
 * message is routed to (a wrong `fromTransport` disconnects a handler without an error).
 */
class AgentVoiceMessengerTransportsTest extends AgentVoiceUnitTestCase
{
    public function testTheTwoTransportsAndTheirRouting(): void
    {
        static::assertSame(['voice_live_session', 'voice_ring_deadline'], AgentVoiceMessengerTransports::ALL);
        static::assertSame(
            [
                VoiceLiveSessionAttachMessage::class => 'voice_live_session',
                AgentVoiceRingDeadlineMessage::class => 'voice_ring_deadline',
            ],
            AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS
        );
    }

    public function testEveryMessageClassOfThePackageIsRouted(): void
    {
        $messageClassNames = static::findPackageClassNamesThatAre(AppMessage::class);

        static::assertNotSame([], $messageClassNames);
        foreach ($messageClassNames as $messageClassName) {
            static::assertArrayHasKey($messageClassName, AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS, $messageClassName . ' has no transport');
        }
        $routedMessageClassNames = array_keys(AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS);
        sort($routedMessageClassNames);
        static::assertSame($messageClassNames, $routedMessageClassNames, 'no routing line for a class that is no message of the package');
    }

    public function testEveryRoutedTransportIsDeclared(): void
    {
        foreach (AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS as $messageClassName => $transportName) {
            static::assertContains($transportName, AgentVoiceMessengerTransports::ALL, $messageClassName);
        }
        static::assertSame(array_values(array_unique(AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS)), AgentVoiceMessengerTransports::ALL);
    }

    public function testEveryHandlerConsumesTheTransportItsMessageIsRoutedTo(): void
    {
        $handlerClassNames = static::findPackageClassNamesThatAre(AppMessageHandler::class);

        static::assertNotSame([], $handlerClassNames);
        foreach ($handlerClassNames as $handlerClassName) {
            $handlerClass = new ReflectionClass($handlerClassName);
            $messageParameterType = $handlerClass->getMethod('__invoke')->getParameters()[0]->getType();
            static::assertInstanceOf(ReflectionNamedType::class, $messageParameterType);
            $messageClassName = $messageParameterType->getName();
            $asMessageHandlerAttributes = $handlerClass->getAttributes(AsMessageHandler::class);
            static::assertCount(1, $asMessageHandlerAttributes, $handlerClassName);
            static::assertSame(
                AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS[$messageClassName] ?? null,
                $asMessageHandlerAttributes[0]->newInstance()->fromTransport,
                $handlerClassName
            );
            static::assertSame($handlerClassName, $messageClassName::$messageHandler, $messageClassName . ' names its handler');
        }
    }

    /**
     * The concrete classes below a `Voice\` folder of the package's `src/` that extend `$parentClassName`, sorted.
     *
     * @return string[]
     */
    protected static function findPackageClassNamesThatAre(string $parentClassName): array
    {
        $sourceDirectory = dirname(__DIR__, 7) . '/src';
        $classNames = [];
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $sourceFile) {
            if ($sourceFile->getExtension() !== 'php') {
                continue;
            }
            $className = 'DDD\\' . str_replace('/', '\\', substr($sourceFile->getPathname(), strlen($sourceDirectory) + 1, -4));
            if (!str_contains($className, '\\Voice\\') || !class_exists($className)) {
                continue;
            }
            $reflectionClass = new ReflectionClass($className);
            if (!$reflectionClass->isAbstract() && $reflectionClass->isSubclassOf($parentClassName)) {
                $classNames[] = $className;
            }
        }
        sort($classNames);
        return $classNames;
    }
}
