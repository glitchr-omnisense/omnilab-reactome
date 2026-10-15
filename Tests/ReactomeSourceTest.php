<?php

namespace Omnilab\Reactome\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Reactome\ReactomeSource;
use Omnilab\Reactome\ReactomeSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from reactome.org/ContentService on 2026-10-15
 * (release 97): glycolysis (R-HSA-70171) and one of its reactions
 * (R-HSA-70420) with their participants, ATP in the cytosol (a physical
 * entity, R-ALL-113592), an unknown id (404); the search for "glycolysis"
 * (two pages of three, 76 matches) and for a text nothing matches (404);
 * the pathways and reactions of hemoglobin beta (UniProt P68871) and the
 * pathways of glucose (ChEBI 4167), in human.
 */
final class ReactomeSourceTest extends TestCase
{
    /** @var list<string> the paths asked, with their queries */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): ReactomeSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertStringStartsWith('/ContentService/', $path);
            $raw = (string) parse_url($url, \PHP_URL_QUERY);
            $this->calls[] = $call = substr($path, \strlen('/ContentService/')).('' === $raw ? '' : '?'.rawurldecode($raw));
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                'data/query/R-HSA-70171' === $call => 'query-R-HSA-70171.json',
                'data/participants/R-HSA-70171' === $call => 'participants-R-HSA-70171.json',
                'data/query/R-HSA-70420' === $call => 'query-R-HSA-70420.json',
                'data/participants/R-HSA-70420' === $call => 'participants-R-HSA-70420.json',
                'data/query/R-ALL-113592' === $call => 'query-R-ALL-113592.json',
                str_starts_with($call, 'search/query?') && 'glycolysis' === $query['query'] => '0' === $query['Start_row'] ? 'search-glycolysis-0.json' : 'search-glycolysis-3.json',
                'data/mapping/UniProt/P68871/pathways?species=9606' === $call => 'mapping-uniprot-P68871-pathways.json',
                'data/mapping/UniProt/P68871/reactions?species=9606' === $call => 'mapping-uniprot-P68871-reactions.json',
                'data/mapping/ChEBI/4167/pathways?species=9606' === $call => 'mapping-chebi-4167-pathways.json',
                str_starts_with($call, 'search/') => 'search-notfound.json',
                default => 'query-notfound.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => str_contains($fixture, 'notfound') ? 404 : 200]);
        });
        $source = (new ReactomeSourceFactory($http))->create($options);
        self::assertInstanceOf(ReactomeSource::class, $source);

        return $source;
    }

    public function testGlycolysisAndItsParticipantsInTwoCalls(): void
    {
        $pathway = $this->source()->entity('R-HSA-70171');

        self::assertSame('reactome:R-HSA-70171', $pathway->id->key());
        self::assertSame('Glycolysis', $pathway->label);
        self::assertSame(Domain::BIOLOGY, $pathway->domain);
        self::assertSame('pathway', $pathway->kind);
        self::assertSame('https://reactome.org/content/detail/R-HSA-70171', $pathway->url);
        self::assertStringStartsWith('The reactions of glycolysis (e.g., van Wijk and van Solinge 2005) convert glucose 6-phosphate to pyruvate.', (string) $pathway->description);
        self::assertSame('Homo sapiens', $pathway->value('species'));
        self::assertSame('cytosol (GO:0005829)', $pathway->value('compartment'));
        self::assertSame('canonical glycolysis (GO:0061621)', $pathway->value('go_biological_process'));
        self::assertSame('five stars', $pathway->value('review_status'));
        self::assertFalse($pathway->value('inferred'));
        self::assertSame('2003-02-03', $pathway->value('released'));
        self::assertSame('R-HSA-70171.10', $pathway->value('version'));
        self::assertSame(16, $pathway->value('events'));
        self::assertSame('reactome', $pathway->property('species')->source);
        self::assertSame(['reactome:R-HSA-70171'], $pathway->identifiers()->keys(), 'no other base names a pathway');
        self::assertSame(['ncbi.taxon:9606'], $pathway->related('organism')->keys());
        self::assertCount(16, $pathway->related('event'));
        self::assertContains('reactome:R-HSA-70420', $pathway->related('event')->keys());
        self::assertContains('reactome:R-MMU-70171', $pathway->related('ortholog')->keys(), 'the mouse\'s, inferred');
        self::assertCount(79, $pathway->related('protein'));
        self::assertContains('uniprot:P35557', $pathway->related('protein')->keys(), 'GCK');
        self::assertCount(32, $pathway->related('molecule'));
        self::assertContains('chebi:30616', $pathway->related('molecule')->keys(), 'ATP(4-)');
        self::assertSame(['data/query/R-HSA-70171', 'data/participants/R-HSA-70171'], $this->calls);
    }

    public function testAReaction(): void
    {
        $reaction = $this->source()->entity(Identifier::of(Scheme::REACTOME, 'R-HSA-70420'));

        self::assertSame('HK1,2,3,GCK,HKDC1 phosphorylate Glc to form G6P', $reaction->label);
        self::assertSame('reaction', $reaction->kind);
        self::assertSame(['alpha-D-Glucose + ATP => alpha-D-glucose 6-phosphate + ADP'], $reaction->synonyms);
        self::assertSame('transition', $reaction->value('category'));
        self::assertSame(['ATP [cytosol]', 'Glc [cytosol]'], array_map('strval', $reaction->properties('input')));
        self::assertSame(['ADP [cytosol]', 'G6P [cytosol]', 'H+ [cytosol]'], array_map('strval', $reaction->properties('output')));
        self::assertSame('glucokinase activity of glucokinase and hexokinases [cytosol]', $reaction->value('catalyst_activity'));
        self::assertSame('RHEA:17826', $reaction->value('rhea'));
        self::assertSame('https://reactome.org/ContentService/exporter/reaction/R-HSA-70420.png', $reaction->image);
        self::assertSame(['uniprot:Q2TB90', 'uniprot:P35557', 'uniprot:P19367', 'uniprot:P52789', 'uniprot:P52790'], $reaction->related('protein')->keys());
        self::assertSame(['chebi:15378', 'chebi:456216', 'chebi:58225', 'chebi:30616', 'chebi:4167'], $reaction->related('molecule')->keys());
        self::assertSame([], $reaction->related('event')->keys());
    }

    public function testTheIdentifierInItsForms(): void
    {
        foreach (['R-HSA-70420.7', 'reactome:R-HSA-70420', 'https://reactome.org/content/detail/R-HSA-70420', 'r-hsa-70420'] as $id) {
            self::assertSame('reactome:R-HSA-70420', (string) $this->source()->entity($id)?->id, $id);
        }
    }

    public function testWithoutItsParticipants(): void
    {
        $pathway = $this->source(null, ['participants' => false])->entity('R-HSA-70171');

        self::assertCount(0, $pathway->related('protein'));
        self::assertSame(['data/query/R-HSA-70171'], $this->calls, 'one call');
    }

    public function testAPhysicalEntityOrAnUnknownIdIsNull(): void
    {
        self::assertNull($this->source()->entity('R-ALL-113592'), 'ATP in the cytosol: not an event');
        self::assertNull($this->source()->entity('R-HSA-1'), '404');
        self::assertSame(['data/query/R-ALL-113592', 'data/query/R-HSA-1'], $this->calls, 'no participants asked');
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach (['CHEBI:15422', Identifier::of(Scheme::UNIPROT, 'P68871'), 'glycolysis', 'Q60235'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s should be refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testASearchInPages(): void
    {
        $source = $this->source();
        $first = $source->search(new Query('glycolysis', limit: 3));

        self::assertSame(['Glycolysis', 'Regulation of glycolysis by fructose 2,6-bisphosphate metabolism', 'Glucose metabolism'], array_map(static fn ($e) => $e->label, $first->items), 'the highlighting taken off');
        self::assertSame(76, $first->total);
        self::assertSame('3', $first->next);
        self::assertSame('pathway', $first->first()->kind);
        self::assertSame('cytosol (GO:0005829)', $first->first()->value('compartment'));
        self::assertSame('search/query?query=glycolysis&species=Homo sapiens&types=Pathway&types=Reaction&cluster=false&Start row=0&rows=3', $this->calls[0]);

        $second = $source->search(new Query('glycolysis', limit: 3, cursor: $first->next));
        self::assertSame(['R-HSA-5696021', 'R-HSA-5628899', 'R-HSA-9766065'], array_map(static fn ($e) => $e->id->value, $second->items));
        self::assertSame('reaction', $second->first()->kind);

        $none = $source->search(new Query('zzqqxx'));
        self::assertCount(0, $none);
        self::assertSame(0, $none->total);
        self::assertCount(0, $source->search(new Query('glycolysis', Domain::ASTRONOMY)));
        self::assertCount(3, $this->calls, 'another domain asks nothing');
    }

    public function testTheSearchOfEverySpecies(): void
    {
        $this->source(null, ['species' => 'all'])->search(new Query('glycolysis', limit: 3));

        self::assertStringNotContainsString('species', $this->calls[0]);
    }

    public function testThePathwaysOfAProteinAndOfAMolecule(): void
    {
        $source = $this->source();
        $pathways = $source->pathways('P68871');

        self::assertCount(10, $pathways);
        self::assertSame('reactome:R-HSA-1237044', (string) $pathways[0]->id);
        self::assertSame('Erythrocytes take up carbon dioxide and release oxygen', $pathways[0]->label);
        self::assertSame('pathway', $pathways[0]->kind);
        self::assertSame('Homo sapiens', $pathways[0]->value('species'));

        $reactions = $source->pathways(Identifier::of(Scheme::UNIPROT, 'P68871'), reactions: true);
        self::assertCount(28, $reactions);
        self::assertContains('Hemoglobin A binds O2, releasing H+ and CO2', array_map(static fn ($e) => $e->label, $reactions));

        $glucose = $source->pathways('CHEBI:4167');
        self::assertCount(27, $glucose);
        self::assertSame(['data/mapping/UniProt/P68871/pathways?species=9606', 'data/mapping/UniProt/P68871/reactions?species=9606', 'data/mapping/ChEBI/4167/pathways?species=9606'], $this->calls);
    }

    public function testThePathwaysOfAnUnknownProteinAndAnotherBase(): void
    {
        self::assertSame([], $this->source()->pathways('P12345'), 'a 404');
        $this->expectException(NotSupportedException::class);
        $this->source()->pathways('R-HSA-70171');
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('{"code":400,"reason":"BAD_REQUEST","messages":["Species \'Nowhere\' not found"]}', ['http_code' => 400]))->entity('R-HSA-70171');
            self::fail('a 400');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('R-HSA-70171');
    }

    public function testTheFactory(): void
    {
        $factory = new ReactomeSourceFactory(new MockHttpClient());

        self::assertSame('reactome', $factory->getName());
        self::assertSame([Capability::ENTITY, Capability::SEARCH], $factory->create()->capabilities());
    }
}
