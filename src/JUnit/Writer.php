<?php

declare(strict_types=1);

namespace ParaTest\JUnit;

use DOMDocument;
use DOMElement;

use function assert;
use function dirname;
use function file_put_contents;
use function htmlspecialchars;
use function is_dir;
use function is_int;
use function is_string;
use function mkdir;
use function sprintf;
use function str_replace;

use const ENT_XML1;

/** @internal */
final readonly class Writer
{
    private DOMDocument $document;

    public function __construct()
    {
        $this->document               = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
    }

    public function write(TestSuite $testSuite, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $result = file_put_contents($path, $this->getXml($testSuite));
        assert(is_int($result) && 0 < $result);
    }

    /** @return non-empty-string */
    private function getXml(TestSuite $testSuite): string
    {
        $xmlTestsuites = $this->document->createElement('testsuites');
        $xmlTestsuites->appendChild($this->createSuiteNode($testSuite));
        $this->document->appendChild($xmlTestsuites);

        $xml = $this->document->saveXML();
        assert(is_string($xml) && $xml !== '');

        return $xml;
    }

    private function createSuiteNode(TestSuite $parentSuite): DOMElement
    {
        $suiteNode = $this->document->createElement('testsuite');
        $suiteNode->setAttribute('name', $parentSuite->name);
        if ($parentSuite->file !== '') {
            $suiteNode->setAttribute('file', $parentSuite->file);
        }

        $suiteNode->setAttribute('tests', (string) $parentSuite->tests);
        $suiteNode->setAttribute('assertions', (string) $parentSuite->assertions);
        $suiteNode->setAttribute('errors', (string) $parentSuite->errors);
        $suiteNode->setAttribute('failures', (string) $parentSuite->failures);
        $suiteNode->setAttribute('skipped', (string) $parentSuite->skipped);
        $suiteNode->setAttribute('time', (string) $parentSuite->time);

        foreach ($parentSuite->suites as $suite) {
            $suiteNode->appendChild($this->createSuiteNode($suite));
        }

        foreach ($parentSuite->cases as $case) {
            $suiteNode->appendChild($this->createCaseNode($case));
        }

        return $suiteNode;
    }

    private function createCaseNode(TestCase $case): DOMElement
    {
        $caseNode = $this->document->createElement('testcase');

        $caseNode->setAttribute('name', $case->name);
        $caseNode->setAttribute('class', $case->class);
        $caseNode->setAttribute('classname', str_replace('\\', '.', $case->class));
        $caseNode->setAttribute('file', $case->file);
        $caseNode->setAttribute('line', (string) $case->line);
        $caseNode->setAttribute('assertions', (string) $case->assertions);
        $caseNode->setAttribute('time', sprintf('%F', $case->time));

        if ($case instanceof TestCaseWithRetries) {
            $caseNode->setAttribute('retries', (string) $case->retries);
        }

        $defectSource = $case instanceof TestCaseWithRetries ? $case->finalDefect : ($case instanceof TestCaseWithMessage ? $case : null);
        if ($defectSource !== null) {
            if ($defectSource->xmlTagName === MessageType::skipped) {
                $defectNode = $this->document->createElement($defectSource->xmlTagName->toString());
            } else {
                $defectNode = $this->document->createElement($defectSource->xmlTagName->toString(), htmlspecialchars($defectSource->text, ENT_XML1));
                $type       = $defectSource->type;
                if ($type !== null) {
                    $defectNode->setAttribute('type', $type);
                }
            }

            $caseNode->appendChild($defectNode);
        }

        if ($case instanceof TestCaseWithRetries) {
            foreach ($case->priorAttemptFailures as $priorFailure) {
                $retryNode = $this->createRetryChildNode($priorFailure, $case->finalAttemptPassed);
                if ($retryNode === null) {
                    continue;
                }

                $caseNode->appendChild($retryNode);
            }
        }

        return $caseNode;
    }

    /**
     * Builds a Surefire-convention child element (<flakyFailure>, <flakyError>,
     * <rerunFailure>, <rerunError>) for one prior-attempt failure. Returns null
     * when the prior-attempt payload is not a retryable defect (e.g., skipped).
     */
    private function createRetryChildNode(TestCaseWithMessage $priorFailure, bool $finalAttemptPassed): ?DOMElement
    {
        $elementName = match ($priorFailure->xmlTagName) {
            MessageType::failure => $finalAttemptPassed ? 'flakyFailure' : 'rerunFailure',
            MessageType::error   => $finalAttemptPassed ? 'flakyError' : 'rerunError',
            MessageType::skipped => null,
        };

        if ($elementName === null) {
            return null;
        }

        $node = $this->document->createElement($elementName, htmlspecialchars($priorFailure->text, ENT_XML1));
        $type = $priorFailure->type;
        if ($type !== null) {
            $node->setAttribute('type', $type);
        }

        return $node;
    }
}
