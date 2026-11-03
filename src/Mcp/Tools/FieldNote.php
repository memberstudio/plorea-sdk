<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Returns the field-tested notes that match an HTTP status, an exception
 * class or a keyword.
 *
 * The notes ship in resources/field-notes.json, because docs/ never lands
 * in a consumer's vendor/. Each note names the docs/ heading it comes
 * from, and a test fails when that heading no longer exists.
 */
#[IsReadOnly]
class FieldNote extends Tool
{
    /**
     * The most notes one answer returns.
     */
    protected const int LIMIT = 5;

    protected string $description = 'Look up field-tested notes about how the Plorea API really behaves. Pass an HTTP status (403), an SDK exception class (ChargeFailedException), a status string (payment_failed) or a keyword (refund, webhook, retry). Read the matching note before you assume how the API behaves: it often differs from what a reasonable design would do. An unmatched query returns the list of topics.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('An HTTP status, an exception class (short or fully qualified), a status string or a keyword.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = $this->normalise((string) $request->get('query', ''));

        if ($query === '') {
            return Response::error('Pass a query: an HTTP status, an exception class or a keyword.');
        }

        $matches = $this->matching($query);

        if ($matches === []) {
            return Response::json([
                'query' => $query,
                'notes' => [],
                'topics' => array_map(
                    fn (array $note): array => ['id' => $note['id'], 'title' => $note['title']],
                    static::notes(),
                ),
            ]);
        }

        return Response::json([
            'query' => $query,
            'notes' => array_map(fn (array $note): array => [
                'title' => $note['title'],
                'evidence' => $note['evidence'],
                'note' => $note['note'],
                'source' => $note['source']['file'].' — "'.$note['source']['heading'].'" (memberflow/plorea repository)',
            ], $matches),
        ]);
    }

    /**
     * Every note, in file order.
     *
     * @return list<array{id: string, title: string, triggers: list<string>, evidence: string, note: string, source: array{file: string, heading: string}}>
     */
    public static function notes(): array
    {
        /** @var list<array{id: string, title: string, triggers: list<string>, evidence: string, note: string, source: array{file: string, heading: string}}> $notes */
        $notes = json_decode(
            (string) file_get_contents(__DIR__.'/../../../resources/field-notes.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $notes;
    }

    /**
     * Notes whose trigger equals the query first, then notes whose trigger
     * or title contains it.
     *
     * @return list<array{id: string, title: string, triggers: list<string>, evidence: string, note: string, source: array{file: string, heading: string}}>
     */
    protected function matching(string $query): array
    {
        $exact = [];
        $partial = [];

        foreach (static::notes() as $note) {
            $triggers = array_map(Str::lower(...), $note['triggers']);

            if (in_array($query, $triggers, true)) {
                $exact[] = $note;

                continue;
            }

            if (mb_strlen($query) < 3) {
                continue;
            }

            foreach ([...$triggers, Str::lower($note['title'])] as $haystack) {
                if (str_contains($haystack, $query)) {
                    $partial[] = $note;

                    break;
                }
            }
        }

        return array_slice([...$exact, ...$partial], 0, self::LIMIT);
    }

    /**
     * Lower-cases the query and reduces a fully qualified class name to its
     * short name.
     */
    protected function normalise(string $query): string
    {
        return Str::lower(trim(class_basename(trim($query, ' \\'))));
    }
}
