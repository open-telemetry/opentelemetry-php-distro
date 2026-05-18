<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use CurlHandle;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LoggableTrait;
use PHPUnit\Framework\Assert;

final class CurlHandleForTests implements LoggableInterface
{
    use LoggableTrait;

    private ?CurlHandle $curlHandle;
    private ?string $lastVerboseOutput = null;

    public function __construct(
        CurlHandle $curlHandle,
        private readonly ResourcesCleanerClient $resourcesCleanerClient
    ) {
        $this->curlHandle = $curlHandle;
    }

    public function setOpt(int $option, mixed $value): bool
    {
        Assert::assertNotNull($this->curlHandle);
        return curl_setopt($this->curlHandle, $option, $value);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    public function setOptArray(array $options): bool
    {
        Assert::assertNotNull($this->curlHandle);
        return curl_setopt_array($this->curlHandle, $options);
    }

    public function exec(): string|bool
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        Assert::assertNotNull($this->curlHandle);

        $verboseOutputFilePath = $this->resourcesCleanerClient->createTempFile(FileUtil::generateTempFileNamePrefix(ClassNameUtil::fqToShortFromRawString(__CLASS__) . '_curl_verbose_output'));
        $dbgCtx->add(compact('verboseOutputFilePath'));
        /** @var null|resource|false $verboseOutputFile */
        $verboseOutputFile = null;
        $isAfterCurlExec = false;
        try {
            $verboseOutputFile = fopen($verboseOutputFilePath, 'w'); // open file for write
            Assert::assertIsResource($verboseOutputFile, 'Failed to open temp file for curl verbose output; ' . LoggableToString::convert(compact('verboseOutputFilePath')));
            Assert::assertTrue($this->setOpt(CURLOPT_VERBOSE, true));
            Assert::assertTrue($this->setOpt(CURLOPT_STDERR, $verboseOutputFile));
            $retVal = curl_exec($this->curlHandle);
            $isAfterCurlExec = true;
        } finally {
            if (is_resource($verboseOutputFile)) {
                Assert::assertTrue(fflush($verboseOutputFile));
                Assert::assertTrue(fclose($verboseOutputFile));
                if ($isAfterCurlExec) {
                    $verboseOutput = file_get_contents($verboseOutputFilePath);
                    Assert::assertIsString($verboseOutput);
                    $this->lastVerboseOutput = $verboseOutput;
                }
                Assert::assertTrue(unlink($verboseOutputFilePath));
                $verboseOutputFile = null;
            }
        }

        return $retVal;
    }

    public function error(): string
    {
        Assert::assertNotNull($this->curlHandle);
        return curl_error($this->curlHandle);
    }

    public function errno(): int
    {
        Assert::assertNotNull($this->curlHandle);
        return curl_errno($this->curlHandle);
    }

    public function lastVerboseOutput(): ?string
    {
        return $this->lastVerboseOutput;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getInfo(): array
    {
        Assert::assertNotNull($this->curlHandle);
        return curl_getinfo($this->curlHandle);
    }

    public function getResponseStatusCode(): mixed
    {
        Assert::assertNotNull($this->curlHandle);
        return curl_getinfo($this->curlHandle, CURLINFO_RESPONSE_CODE);
    }

    public function close(): void
    {
        Assert::assertNotNull($this->curlHandle);
        curl_close($this->curlHandle);
        $this->curlHandle = null;
    }
}
