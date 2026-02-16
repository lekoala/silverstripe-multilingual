<?php

namespace LeKoala\Multilingual;

trait TranslatorUtils
{
    /**
     * Ensure that variables in the original string are present in the translation
     * and restore them if they are translated or missing
     *
     * @param string $originalString
     * @param string $translation
     * @return string
     */
    protected function fixVariables(string $originalString, string $translation): string
    {
        // Extract variables from original string like {name}
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $originalString, $matches);
        $vars = $matches[0] ?? [];

        if (empty($vars)) {
            return $translation;
        }

        // Extract variables from translation
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $translation, $matchesTranslation);
        $varsTranslation = $matchesTranslation[0] ?? [];

        // If names match, all good
        if ($vars === $varsTranslation) {
            return $translation;
        }

        // If counts match, we assume order is preserved
        if (count($vars) === count($varsTranslation)) {
            foreach ($vars as $i => $var) {
                if ($varsTranslation[$i] !== $var) {
                     // Replace the translated variable with the original one
                     // We use preg_replace to replace only the first occurrence found
                     $translation = preg_replace('/' . preg_quote($varsTranslation[$i], '/') . '/', $var, $translation, 1);
                }
            }
        } else {
             // If counts don't match, it gets tricky.
             // Strategy: find variables in translation that are NOT in original, and try to map them to variables in original that are MISSING in translation
             // This is heuristic and might fail if there are multiple variables
             $missingVars = array_diff($vars, $varsTranslation);
             $extraVars = array_diff($varsTranslation, $vars);

             // If we have 1 missing and 1 extra, we can swap them
            if (count($missingVars) === 1 && count($extraVars) === 1) {
                $missingVar = reset($missingVars);
                $extraVar = reset($extraVars);
                $translation = str_replace($extraVar, $missingVar, $translation);
            }
        }

        return $translation;
    }
}
