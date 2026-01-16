<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Email\Test\Unit\Model;

use Magento\Email\Model\Transport;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\EmailMessageInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface as SymfonyTransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message as SymfonyMessage;

/**
 * Tests for email transport functionality.
 *
 * @coversDefaultClass \Magento\Email\Model\Transport
 */
class TransportTest extends TestCase
{
    /**
     * @var LoggerInterface&MockObject
     */
    private $loggerMock;

    /**
     * @var SymfonyMessage&MockObject
     */
    private $symfonyMessageMock;

    /**
     * @var EmailMessage&MockObject
     */
    private $emailMessageMock;

    /**
     * @var Transport
     */
    private $transport;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfigMock;

    /**
     * @inheritdoc
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->symfonyMessageMock = $this->createMock(SymfonyMessage::class);
        $this->symfonyMessageMock->expects($this->any())
            ->method('getHeaders')
            ->willReturn(new Headers());
        $this->emailMessageMock = $this->getMockBuilder(EmailMessage::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailMessageMock->expects($this->any())
            ->method('getSymfonyMessage')
            ->willReturn($this->symfonyMessageMock);

        $this->transport = new Transport(
            $this->emailMessageMock,
            $this->scopeConfigMock,
            $this->loggerMock
        );
    }

    /**
     * Create a scope config mock with the given configuration values.
     *
     * @param array $config
     * @return ScopeConfigInterface&MockObject
     */
    private function createScopeConfigMock(array $config): ScopeConfigInterface
    {
        $defaults = [
            Transport::XML_PATH_SENDING_SET_RETURN_PATH => '0',
            Transport::XML_PATH_SENDING_RETURN_PATH_EMAIL => null,
            'system/smtp/transport' => 'sendmail',
            'system/smtp/host' => 'smtp.example.com',
            'system/smtp/port' => '587',
            'system/smtp/username' => 'user@example.com',
            'system/smtp/password' => 'password123',
            'system/smtp/auth' => 'login',
            'system/smtp/ssl' => 'tls',
        ];
        $config = array_merge($defaults, $config);

        $mock = $this->createMock(ScopeConfigInterface::class);
        $mock->expects($this->atLeastOnce())
            ->method('getValue')
            ->willReturnCallback(fn($path) => $config[$path] ?? null);

        return $mock;
    }

    /**
     * Verify exception is properly handled in case one occurred when message sent.
     *
     * @return void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function testSendMessageBrokenMessage(): void
    {
        $exception = new RfcComplianceException('Email "" does not comply with addr-spec of RFC 2822.');
        $this->loggerMock->expects(self::once())->method('error')->with($exception);
        $this->expectException('Magento\Framework\Exception\MailException');
        $this->expectExceptionMessage('Unable to send mail. Please try again later.');

        $this->transport->sendMessage();
    }

    /**
     * Test setReturnPath behavior with various configurations.
     *
     * @param string $isSetReturnPath
     * @param string|null $returnPathEmail
     * @param string|null $fromEmail
     * @param string|null $expectedSender
     * @return void
     * @dataProvider setReturnPathDataProvider
     * @covers ::setReturnPath
     */
    public function testSetReturnPath(
        string $isSetReturnPath,
        ?string $returnPathEmail,
        ?string $fromEmail,
        ?string $expectedSender
    ): void {
        $email = new Email();
        if ($fromEmail) {
            $email->from($fromEmail);
        }
        $email->to('recipient@example.com');
        $email->subject('Test');
        $email->text('Test body');

        $scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $scopeConfigMock->expects($this->exactly(2))
            ->method('getValue')
            ->willReturnCallback(fn($path) => match ($path) {
                Transport::XML_PATH_SENDING_SET_RETURN_PATH => $isSetReturnPath,
                Transport::XML_PATH_SENDING_RETURN_PATH_EMAIL => $returnPathEmail,
                default => null
            });

        $transport = new Transport(
            $this->createMock(EmailMessage::class),
            $scopeConfigMock,
            $this->loggerMock
        );

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('setReturnPath');
        $method->setAccessible(true);
        $method->invoke($transport, $email);

        $senderHeader = $email->getHeaders()->get('Sender');

        if ($expectedSender === null) {
            $this->assertNull($senderHeader, 'Sender header should not be set');
        } else {
            $this->assertNotNull($senderHeader, 'Sender header should be set');
            $this->assertStringContainsString($expectedSender, $senderHeader->getBodyAsString());
        }
    }

    /**
     * Data provider for setReturnPath tests.
     *
     * @return array
     */
    public static function setReturnPathDataProvider(): array
    {
        return [
            'custom return path when isSetReturnPath is 2' => [
                'isSetReturnPath' => '2',
                'returnPathEmail' => 'return@example.com',
                'fromEmail' => 'sender@example.com',
                'expectedSender' => 'return@example.com',
            ],
            'from address when isSetReturnPath is 1' => [
                'isSetReturnPath' => '1',
                'returnPathEmail' => null,
                'fromEmail' => 'sender@example.com',
                'expectedSender' => 'sender@example.com',
            ],
            'no sender when isSetReturnPath is 1 but from is empty' => [
                'isSetReturnPath' => '1',
                'returnPathEmail' => null,
                'fromEmail' => null,
                'expectedSender' => null,
            ],
            'no sender when isSetReturnPath is 0' => [
                'isSetReturnPath' => '0',
                'returnPathEmail' => null,
                'fromEmail' => 'sender@example.com',
                'expectedSender' => null,
            ],
        ];
    }

    /**
     * Test getMessage returns the injected email message.
     *
     * @return void
     * @covers ::getMessage
     */
    public function testGetMessageReturnsEmailMessage(): void
    {
        $this->assertInstanceOf(EmailMessageInterface::class, $this->transport->getMessage());
        $this->assertSame($this->emailMessageMock, $this->transport->getMessage());
    }

    /**
     * Data provider for getTransport tests.
     *
     * @return array
     */
    public static function transportTypeDataProvider(): array
    {
        return [
            'SMTP transport' => [
                'transportType' => 'smtp',
                'expectedClass' => EsmtpTransport::class,
            ],
            'Sendmail transport' => [
                'transportType' => 'sendmail',
                'expectedClass' => SymfonyTransportInterface::class,
            ],
            'Null transport defaults to Sendmail' => [
                'transportType' => null,
                'expectedClass' => SymfonyTransportInterface::class,
            ],
        ];
    }

    /**
     * Test getTransport returns correct transport type based on configuration.
     *
     * @param string|null $transportType
     * @param string $expectedClass
     * @return void
     * @dataProvider transportTypeDataProvider
     * @covers ::getTransport
     * @covers ::createSmtpTransport
     * @covers ::createSendmailTransport
     */
    public function testGetTransportReturnsCorrectType(?string $transportType, string $expectedClass): void
    {
        $transport = new Transport(
            $this->createMock(EmailMessage::class),
            $this->createScopeConfigMock(['system/smtp/transport' => $transportType]),
            $this->loggerMock
        );
        $this->assertInstanceOf($expectedClass, $transport->getTransport());
    }

    /**
     * Test getTransport caches the transport instance.
     *
     * @return void
     * @covers ::getTransport
     * @covers ::createSendmailTransport
     */
    public function testGetTransportCachesTransportInstance(): void
    {
        $transport = new Transport(
            $this->createMock(EmailMessage::class),
            $this->createScopeConfigMock([]),
            $this->loggerMock
        );

        $this->assertSame($transport->getTransport(), $transport->getTransport());
    }

    /**
     * Data provider for createSmtpTransport configuration tests.
     *
     * @return array
     */
    public static function smtpConfigDataProvider(): array
    {
        return [
            'TLS + login' => ['ssl' => 'tls', 'auth' => 'login'],
            'TLS + plain' => ['ssl' => 'tls', 'auth' => 'plain'],
            'TLS + none' => ['ssl' => 'tls', 'auth' => 'none'],
            'SSL + login' => ['ssl' => 'ssl', 'auth' => 'login'],
            'SSL + plain' => ['ssl' => 'ssl', 'auth' => 'plain'],
            'SSL + none' => ['ssl' => 'ssl', 'auth' => 'none'],
            'No SSL + login' => ['ssl' => '', 'auth' => 'login'],
            'No SSL + plain' => ['ssl' => '', 'auth' => 'plain'],
            'No SSL + none' => ['ssl' => '', 'auth' => 'none'],
            'Null SSL + none' => ['ssl' => null, 'auth' => 'none'],
        ];
    }

    /**
     * Test createSmtpTransport with various SSL and auth configurations.
     *
     * @param string|null $ssl
     * @param string $auth
     * @return void
     * @dataProvider smtpConfigDataProvider
     * @covers ::getTransport
     * @covers ::createSmtpTransport
     */
    public function testCreateSmtpTransport(?string $ssl, string $auth): void
    {
        $transport = new Transport(
            $this->createMock(EmailMessage::class),
            $this->createScopeConfigMock([
                'system/smtp/transport' => 'smtp',
                'system/smtp/ssl' => $ssl,
                'system/smtp/auth' => $auth,
            ]),
            $this->loggerMock
        );

        $this->assertInstanceOf(EsmtpTransport::class, $transport->getTransport());
    }

    /**
     * Test createSmtpTransport throws exception for invalid auth type.
     *
     * @return void
     * @covers ::getTransport
     * @covers ::createSmtpTransport
     */
    public function testCreateSmtpTransportThrowsExceptionForInvalidAuth(): void
    {
        $transport = new Transport(
            $this->createMock(EmailMessage::class),
            $this->createScopeConfigMock([
                'system/smtp/transport' => 'smtp',
                'system/smtp/auth' => 'invalid_auth_type',
            ]),
            $this->loggerMock
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid authentication type: invalid_auth_type');

        $transport->getTransport();
    }

    /**
     * Test sendMessage logs transport exception and throws MailException.
     *
     * @return void
     * @covers ::sendMessage
     */
    public function testSendMessageLogsTransportExceptionAndThrowsMailException(): void
    {
        $email = new Email();
        $email->from('sender@example.com');
        $email->to('recipient@example.com');
        $email->subject('Test');
        $email->text('Test body');

        $emailMessageMock = $this->createMock(EmailMessage::class);
        $emailMessageMock->expects($this->once())
            ->method('getSymfonyMessage')
            ->willReturn($email);

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains(''));

        $transport = new Transport(
            $emailMessageMock,
            $this->createScopeConfigMock([
                'system/smtp/transport' => 'smtp',
                'system/smtp/host' => 'invalid.host.example',
                'system/smtp/username' => '',
                'system/smtp/password' => '',
                'system/smtp/auth' => 'none',
                'system/smtp/ssl' => '',
            ]),
            $loggerMock
        );

        $this->expectException(MailException::class);

        $transport->sendMessage();
    }
}
