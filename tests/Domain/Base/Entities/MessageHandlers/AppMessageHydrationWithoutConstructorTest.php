<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

/** The normal shape of a typed message: the payload is a REQUIRED constructor argument. */
class ResumeProbeMessage extends AppMessage
{
    public static string $messageHandler = 'ResumeProbeHandler';

    public int $probeConversationId;

    public function __construct(int $probeConversationId)
    {
        parent::__construct();
        $this->probeConversationId = $probeConversationId;
    }
}

class AppMessageHydrationWithoutConstructorTest extends TestCase
{
    protected function probeMessage(int $conversationId = 128482): ResumeProbeMessage
    {
        $message = new ResumeProbeMessage($conversationId);
        $message->accountId = 581;
        $message->dispatchedFromWorkspaceDir = '/var/www/dev-workspaces/prj6/app';
        return $message;
    }

    public function testAMessengerEnvelopeRoundTripsThroughDecode(): void
    {
        $message = $this->probeMessage();
        $encoded = $message->encode(new Envelope($message));

        $decodedEnvelope = $message->decode($encoded);
        $decoded = $decodedEnvelope->getMessage();

        $this->assertInstanceOf(ResumeProbeMessage::class, $decoded);
        $this->assertSame(128482, $decoded->probeConversationId, 'the payload must survive hydration');
        $this->assertSame(581, $decoded->accountId);
    }

    public function testTheCommandlineFormRoundTrips(): void
    {
        $message = $this->probeMessage(135306);

        $decoded = AppMessage::decodeFromCommandline($message->encodeForCommandline());

        $this->assertInstanceOf(ResumeProbeMessage::class, $decoded);
        $this->assertSame(135306, $decoded->probeConversationId);
    }

    public function testTheTempFileTransportRoundTrips(): void
    {
        // the temp-file transport of app:process-cli-message, which since 2.66.0 is a MANUAL REPLAY rather than the
        // automatic cross-workspace reroute: persistToTempDir() here, loadFromTempDir() in the console that replays
        // it — this is where a message with a required constructor argument used to die with
        // "Too few arguments to __construct(), 0 passed in …/AppMessage.php"
        $message = $this->probeMessage(9534);
        $tempDirFileName = $message->persistToTempDir();

        $loaded = AppMessage::loadFromTempDir($tempDirFileName);

        $this->assertInstanceOf(ResumeProbeMessage::class, $loaded);
        $this->assertSame(9534, $loaded->probeConversationId);
        $this->assertSame('/var/www/dev-workspaces/prj6/app', $loaded->dispatchedFromWorkspaceDir,
            'the recorded directory must survive — it is what tells an operator where a replayed message came from');
        $this->assertFileDoesNotExist(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempDirFileName,
            'the temp file is consumed');
    }

    public function testHydrationDoesNotRunTheConstructor(): void
    {
        $message = $this->probeMessage();
        $loaded = AppMessage::decodeFromCommandline($message->encodeForCommandline());

        // AppMessage declares no constructor of its own, so skipping the subclass constructor skips nothing the
        // base class relies on; every property comes back through setPropertiesFromObject()
        $this->assertFalse((new \ReflectionClass(AppMessage::class))->hasMethod('__construct')
            && (new \ReflectionClass(AppMessage::class))->getConstructor()?->getDeclaringClass()->getName() === AppMessage::class,
            'AppMessage must not declare a constructor that hydration would skip');
        $this->assertSame(128482, $loaded->probeConversationId);
    }

    public function testInlinePropertyDefaultsStillApply(): void
    {
        // newInstanceWithoutConstructor() skips the constructor, not the declared defaults
        $probe = new class (1) extends AppMessage {
            public static string $messageHandler = 'DefaultsProbeHandler';
            public string $withDefault = 'still here';
            public int $id;
            public function __construct(int $id) { parent::__construct(); $this->id = $id; }
        };
        $hydrated = (new \ReflectionClass($probe::class))->newInstanceWithoutConstructor();
        $this->assertSame('still here', $hydrated->withDefault);
    }
}
