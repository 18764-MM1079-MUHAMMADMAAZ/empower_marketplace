<?php

namespace App\Support;

use App\Models\AiUsageLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Derives a questionnaire's AI-extraction schema from the merge fields actually present in an
 * uploaded manual template — the same {prefix, count, extra_fields} shape
 * ManualQuestionSets::forDocumentType() used to hand-write per type. Structure (field names,
 * question count) comes straight from the template's own PhpWord merge fields (ground truth, never
 * guessed); only the extra fields' human-readable descriptions are AI-drafted, since that's the one
 * piece a field's own name doesn't fully convey — and the admin reviews/edits every draft before it's
 * saved, so a bad guess there degrades one field's extraction prompt, not the document's structure.
 */
class QuestionnaireSchemaGenerator
{
    /**
     * Practice-level fields generateDocx() fills unconditionally regardless of schema — never
     * part of a questionnaire's own extraction schema even if present in its template.
     */
    private const ALWAYS_FILLED_FIELDS = [
        'practice_name', 'practice_address', 'specialty', 'provider_count',
        'package_name', 'date', 'osha_location_name', 'practice_logo',
    ];

    /**
     * @return array{prefix: string, count: int, extra_fields: array<string, string>}
     */
    public function generate(string $absoluteManualTemplatePath, string $prefix): array
    {
        $variables = array_unique((new TemplateProcessor($absoluteManualTemplatePath))->getVariables());

        $numberedPattern = '/^'.preg_quote($prefix, '/').'_(\d+)_answer$/';
        // ${prefix_nn_block}/${/prefix_nn_block} — the cloneBlock-style markers
        // InsertPolicyBlockMarkers wraps each policy's workflow-description section in, so a
        // policy's section can be deleted outright when unanswered. Structural, not a real
        // field — never treat these as extraction schema "extra fields".
        $blockMarkerPattern = '/^\/?'.preg_quote($prefix, '/').'_(\d+)_block$/';
        $count = 0;
        $extraFieldKeys = [];

        foreach ($variables as $variable) {
            if (in_array($variable, self::ALWAYS_FILLED_FIELDS, true) || preg_match($blockMarkerPattern, $variable)) {
                continue;
            }

            if (preg_match($numberedPattern, $variable, $matches)) {
                $count = max($count, (int) $matches[1]);
            } else {
                $extraFieldKeys[] = $variable;
            }
        }

        return [
            'prefix' => $prefix,
            'count' => $count,
            'extra_fields' => $this->describeFields($extraFieldKeys),
        ];
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    private function describeFields(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $fallback = collect($keys)->mapWithKeys(fn (string $key) => [$key => 'The value for '.str_replace('_', ' ', $key)])->all();

        try {
            $response = Http::withToken(config('services.openai.key'))->timeout(30)->post(
                'https://api.openai.com/v1/chat/completions',
                [
                    'model' => config('services.openai.model'),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [[
                        'role' => 'user',
                        'content' => $this->buildPrompt($keys),
                    ]],
                ]
            );

            $this->logAiUsage($response);

            if ($response->failed()) {
                return $fallback;
            }

            $content = $response->json('choices.0.message.content', '');
            $content = preg_replace('/^```(?:json)?\s*/m', '', $content) ?? $content;
            $content = preg_replace('/```\s*$/m', '', $content) ?? $content;
            $decoded = json_decode(trim($content), true);

            if (! is_array($decoded)) {
                return $fallback;
            }

            // Only keep descriptions for keys we actually asked about, as strings — never trust
            // the AI response to introduce new keys or non-string values into the schema.
            return collect($keys)->mapWithKeys(fn (string $key) => [
                $key => is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '' ? trim($decoded[$key]) : $fallback[$key],
            ])->all();
        } catch (\Throwable $e) {
            AiUsageLog::record(purpose: 'schema_field_description', success: false, message: $e->getMessage());

            Log::warning('QuestionnaireSchemaGenerator: AI field description draft failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    private function logAiUsage(Response $response): void
    {
        AiUsageLog::record(
            purpose: 'schema_field_description',
            success: $response->successful(),
            model: $response->json('model'),
            promptTokens: $response->json('usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens'),
            totalTokens: $response->json('usage.total_tokens'),
            message: $response->failed() ? 'OpenAI API error: '.$response->status() : null,
        );
    }

    /** @param  array<int, string>  $keys */
    private function buildPrompt(array $keys): string
    {
        $keyList = implode("\n", array_map(fn (string $key) => "- {$key}", $keys));

        return <<<PROMPT
These are merge-field variable names from a healthcare compliance document template. For each one,
write a single short plain-English description of what information it holds, suitable as an
instruction telling someone what to look for when reading a filled-in questionnaire.

Variable names:
{$keyList}

Return a JSON object with exactly these keys and no others, each value a one-line description string.
PROMPT;
    }
}
