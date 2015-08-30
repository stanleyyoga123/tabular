<?php

/*
 * This file is part of the Tabular  package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpBench\Tabular\Definition;

use JsonSchema\Validator;
use PhpBench\Tabular\Definition;
use PhpBench\Tabular\PathUtil;
use PhpBench\Tabular\TokenReplacer;

class Loader
{
    /**
     * @var Validator
     */
    private $validator;

    /**
     * @var TokenReplacer
     */
    private $tokenReplacer;

    public function __construct(Validator $validator = null, TokenReplacer $tokenReplacer = null)
    {
        $this->validator = $validator ?: new Validator();
        $this->tokenReplacer = $tokenReplacer ?: new TokenReplacer();
    }

    public function load($definition)
    {
        $definition = $this->normalizeDefinition($definition);

        $this->processDefinitionIncludes($definition);
        $this->validateDefinition($definition);
        $this->processMetadata($definition);

        return $definition;
    }

    private function normalizeDefinition($definition)
    {
        if ($definition instanceof Definition) {
            return $definition;
        }

        if (is_array($definition)) {
            return new Definition($definition);
        }

        if (!is_string($definition)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid definition type "%s"',
                is_object($definition) ? get_class($definition) : gettype($definition)
            ));
        }

        $definitionArray = $this->loadDefinition($definition);

        return new Definition($definitionArray, $definition);
    }

    private function processMetadata(Definition $definition)
    {
        $columns = array();
        $passes = array();

        foreach ($definition['rows'] as $rowDefinition) {
            foreach ($rowDefinition['cells'] as $cellDefinition) {
                $cellName = $cellDefinition['name'];

                if (isset($cellDefinition['pass'])) {
                    $passes[] = $cellDefinition['pass'];
                }

                $cellItems = array(null);
                if (isset($cellDefinition['with_items'])) {
                    $cellItems = $cellDefinition['with_items'];
                }

                foreach ($cellItems as $cellItem) {
                    $evaledCellName = $this->tokenReplacer->replaceTokens($cellName, null, $cellItem);
                    $columns[] = $evaledCellName;
                }
            }
        }

        sort($passes);

        $definition->setMetadata($columns, $passes);
    }

    private function loadDefinition($filePath)
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException(sprintf(
                'Definition file "%s" does not exist.',
                $filePath
            ));
        }

        $definition = json_decode(file_get_contents($filePath), true);

        if (null === $definition) {
            throw new \RuntimeException(sprintf(
                'Could not decode JSON file "%s"',
                $filePath
            ));
        }

        return $definition;
    }

    private function validateDefinition(Definition $definition)
    {
        $definition = json_decode(json_encode($definition));
        $this->validator->check($definition, json_decode(file_get_contents(__DIR__ . '/../schema/table.json')));

        if (!$this->validator->isValid()) {
            $errorString = array();
            foreach ($this->validator->getErrors() as $error) {
                $errorString[] = sprintf('[%s] %s', $error['property'], $error['message']);
            }

            throw new \InvalidArgumentException(sprintf(
                'Invalid table definition: %s%s',
                PHP_EOL . PHP_EOL, implode(PHP_EOL, $errorString)
            ));
        }
    }

    /**
     * Merge any included definitions.
     */
    private function processDefinitionIncludes(Definition $definition)
    {
        if (!isset($definition['includes']) || empty($definition['includes'])) {
            return;
        }

        $baseDefinition = array();
        $validKeys = array('rows', 'sort', 'classes', 'params', 'includes');

        foreach ($definition['includes'] as $include) {
            $keys = array();
            if (count($include) == 2) {
                list($file, $keys) = $include;
            } elseif (count($include) == 1) {
                list($file) = $include;
            } else {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid number of arguments given for "include" configuration key, got: "%s"',
                    print_r($include, true)
                ));
            }

            if (empty($keys)) {
                $keys = $validKeys;
            }

            $filePath = PathUtil::getPath($file, $definition->getBasePath());
            $includeDefinition = $this->loadDefinition($filePath);
            $baseDefinition = $this->mergeDefinition($baseDefinition, $includeDefinition, $keys);
        }

        $definition->exchangeArray(
            $this->mergeDefinition($baseDefinition, $definition->getArrayCopy(), $validKeys)
        );
    }

    private function mergeDefinition($baseDefinition, $definition, array $keys)
    {
        foreach ($keys as $key) {
            // keys are already validated by JSON schema, so just skip them if they are not existing
            if (!isset($definition[$key])) {
                continue;
            }

            if (isset($baseDefinition[$key])) {
                $baseDefinition[$key] = array_merge(
                    $baseDefinition[$key], $definition[$key]
                );
            } else {
                $baseDefinition[$key] = $definition[$key];
            }
        }

        return $baseDefinition;
    }
}
