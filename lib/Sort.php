<?php

namespace PhpBench\Tabular;

use PhpBench\Tabular\Dom\Element;
use PhpBench\Tabular\Dom\Document;
use PhpBench\Tabular\Tabulizer;

class Sort
{
    public static function sortTable(Document $tableDom, array $sortDefinition)
    {
        $groups = array();

        foreach (array_reverse($sortDefinition) as $columnSpecifier => $direction) {
            if (is_numeric($columnSpecifier)) {
                $columnSpecifier = $direction;
                $direction = 'asc';
            }

            $parts = explode('#', $columnSpecifier);

            if (count($parts) === 1) {
                $group = Tabulizer::DEFAULT_GROUP;
                $columnName = $parts[0];
            } else {
                $group = $parts[0];
                $columnName = $parts[1];
            }

            if (!isset($groups[$group])) {
                $rowEls = $tableDom->xpath()->query('//group[@name="' . $group . '"]/row');
                $rowEls = iterator_to_array($rowEls);
                $groups[$group] = $rowEls;
            } else {
                $rowEls = $groups[$group];
            }

            Sort::mergesort($rowEls, function (Element $rowEl1, Element $rowEl2) use ($columnName, $direction) {
                $cellEl1 = $rowEl1->query('.//cell[@name="' . $columnName . '"]')->item(0);
                $cellEl2 = $rowEl2->query('.//cell[@name="' . $columnName . '"]')->item(0);

                if (null === $cellEl1 || null === $cellEl2) {
                    throw new \InvalidArgumentException(sprintf('Unknown column "%s"', $columnName));
                }

                $row1Value = $cellEl1->nodeValue;
                $row2Value = $cellEl2->nodeValue;

                if ($row1Value == $row2Value) {
                    return 0;
                }

                $greaterThan = $row1Value > $row2Value;

                if (strtolower($direction) === 'asc') {
                    return $greaterThan ? 1 : -1;
                }

                return $greaterThan ? -1 : 1;
            });

            $groups[$group] = $rowEls;
        }

        foreach ($groups as $group => $rowEls) {
            $groupEl = $tableDom->xpath()->query('//group[@name="' . $group . '"]')->item(0);
            foreach ($groupEl->childNodes as $childNode) {
                $groupEl->removeChild($childNode);
            }

            foreach ($rowEls as $rowEl) {
                $groupEl->appendChild($rowEl);
            }
        }
    }

    /**
     * Merge sort -- similar to usort but preserves order when comparison.
     *
     * http://at2.php.net/manual/en/function.usort.php#38827
     *
     * @param array
     * @param \Closure Sorting callback
     */
    public static function mergeSort(&$array, \Closure $callback)
    {
        // Arrays of size < 2 require no action.
        if (count($array) < 2) {
            return;
        }

        // Split the array in half
        $halfway = count($array) / 2;
        $array1 = array_slice($array, 0, $halfway);
        $array2 = array_slice($array, $halfway);

        // Recurse to sort the two halves
        self::mergesort($array1, $callback);
        self::mergesort($array2, $callback);

        // If all of $array1 is <= all of $array2, just append them.
        if (call_user_func($callback, end($array1), $array2[0]) < 1) {
            $array = array_merge($array1, $array2);

            return;
        }

        // Merge the two sorted arrays into a single sorted array
        $array = array();
        $ptr1 = $ptr2 = 0;
        while ($ptr1 < count($array1) && $ptr2 < count($array2)) {
            if ($callback($array1[$ptr1], $array2[$ptr2]) < 1) {
                $array[] = $array1[$ptr1++];
            } else {
                $array[] = $array2[$ptr2++];
            }
        }
        // Merge the remainder
        while ($ptr1 < count($array1)) {
            $array[] = $array1[$ptr1++];
        }

        while ($ptr2 < count($array2)) {
            $array[] = $array2[$ptr2++];
        }

        return;
    }
}