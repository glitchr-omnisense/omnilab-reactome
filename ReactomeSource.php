<?php

namespace Omnilab\Reactome;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reactome, the curated knowledgebase of biological pathways and
 * reactions (EMBL-EBI, OICR, NYU, OHSU), through its Content Service: a
 * pathway or a reaction by its stable identifier ("R-HSA-70171",
 * glycolysis; "R-HSA-70420", a reaction of it) - its name and other
 * names, species, summary, compartments, GO biological process, Rhea
 * reaction, inputs and outputs, review status, release and update dates,
 * DOI, its diagram; its participants as related things (the UniProt
 * proteins and the ChEBI small molecules it involves), its organism, its
 * sub-events and its inferred orthologous events; a search by text; the
 * pathways or reactions of a protein or a molecule (pathways(), outside
 * the contract: UniProt -> Reactome).
 *
 * No key, no account, no published rate: no throttle by default. Two calls
 * an entity (the event, its participants), one a search page.
 */
final class ReactomeSource extends HttpSource
{
    public const BASE_URI = 'https://reactome.org/ContentService/';

    /** The most entries a search page holds. */
    public const MAX_LIMIT = 100;

    /** Reactome's classes of events: what entity() reads. */
    private const EVENTS = ['Pathway', 'TopLevelPathway', 'CellLineagePath', 'Reaction', 'BlackBoxEvent', 'Polymerisation', 'Depolymerisation', 'FailedReaction', 'CellDevelopmentStep'];

    /**
     * @param string|null $species  the species a search and pathways() keep ("Homo sapiens", or a taxon id for pathways()); null: every species
     * @param bool        $participants whether entity() asks for the participants (a second call; thousands for a top-level pathway)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $species = 'Homo sapiens',
        private readonly bool $participants = true,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'reactome';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH];
    }

    /**
     * A pathway or a reaction by its stable identifier ("R-HSA-70171",
     * versioned or not, "reactome:R-HSA-70171", its page); another base's
     * identifier is refused before any call; null when Reactome has no such
     * identifier, or when it names a physical entity (a protein, a complex,
     * a molecule: not an event).
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::REACTOME) ?? throw NotSupportedException::identifier('reactome', (string) $id);
        $event = $this->getJson('data/query/'.rawurlencode($identifier->value));
        if (null === $event || !\in_array($event['schemaClass'] ?? $event['className'] ?? null, self::EVENTS, true)) {
            return null;
        }
        $participants = $this->participants ? ($this->getJson('data/participants/'.rawurlencode($identifier->value)) ?? []) : null;

        return $this->toEntity($event, $participants);
    }

    /**
     * The pathways and reactions whose text holds the words, Reactome's
     * ranking, in the configured species; a domain other than biology
     * answers nothing. An entity of a page holds what the search gives (its
     * name, type, species, summary, compartments): entity() reads it whole.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::BIOLOGY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $start = max(0, (int) $query->cursor);
        $rows = max(1, min(self::MAX_LIMIT, $query->limit));
        // "Start row", with its space, is the parameter's name (the Content Service's OpenAPI): "start" is ignored.
        $data = $this->getJson('search/query', ['query' => $text, 'species' => $this->species, 'types' => ['Pathway', 'Reaction'], 'cluster' => 'false', 'Start row' => $start, 'rows' => $rows]);
        if (null === $data) {
            return new Page([], null, 0); // 404: "No entries found for query"
        }
        $entries = [];
        foreach ($data['results'] ?? [] as $group) {
            array_push($entries, ...($group['entries'] ?? []));
        }
        $total = isset($data['numberOfMatches']) ? (int) $data['numberOfMatches'] : null;
        $items = array_values(array_filter(array_map(fn (array $entry) => $this->fromSearch($entry), $entries)));

        return new Page($items, null !== $total && $start + $rows < $total ? (string) ($start + $rows) : null, $total);
    }

    /**
     * The pathways (or the reactions) a protein or a small molecule takes
     * part in, the lowest-level ones, in the configured species: a UniProt
     * accession ("P68871", "uniprot:P68871") or a ChEBI id ("CHEBI:15422").
     * Outside the contract: the core asks one thing of a source, not the
     * things another one is part of. Each entity holds its name, species
     * and type: entity() reads it whole.
     *
     * @return list<Entity>
     */
    public function pathways(Identifier|string $participant, bool $reactions = false): array
    {
        $identifier = self::identify($participant, Scheme::UNIPROT, Scheme::CHEBI) ?? throw NotSupportedException::identifier('reactome', (string) $participant);
        $resource = Scheme::UNIPROT === $identifier->scheme ? 'UniProt' : 'ChEBI';
        $events = $this->getJson('data/mapping/'.$resource.'/'.rawurlencode($identifier->value).'/'.($reactions ? 'reactions' : 'pathways'), ['species' => $this->taxon()]) ?? [];

        return array_values(array_filter(array_map(fn (mixed $event) => \is_array($event) ? $this->light($event) : null, $events)));
    }

    /**
     * @param array<string, mixed>           $event
     * @param list<array<string, mixed>>|null $participants
     */
    private function toEntity(array $event, ?array $participants): Entity
    {
        $id = Identifier::of(Scheme::REACTOME, (string) $event['stId']);
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'reactome');
            }
        };
        $class = (string) ($event['schemaClass'] ?? $event['className']);
        $add('species', 'Species', self::text($event['speciesName'] ?? null));
        foreach ($event['compartment'] ?? [] as $compartment) {
            $add('compartment', 'Compartment', self::term($compartment));
        }
        // One term for a pathway, as an object; a list, should Reactome give several.
        $processes = $event['goBiologicalProcess'] ?? [];
        foreach (isset($processes['accession']) ? [$processes] : $processes as $process) {
            $add('go_biological_process', 'GO biological process', self::term($process));
        }
        $add('category', 'Reaction category', self::text($event['category'] ?? null));
        foreach (['input' => 'Input', 'output' => 'Output'] as $key => $label) {
            foreach ($event[$key] ?? [] as $entity) {
                $add($key, $label, \is_array($entity) ? self::text($entity['displayName'] ?? null) : null);
            }
        }
        foreach ($event['catalystActivity'] ?? [] as $activity) {
            $add('catalyst_activity', 'Catalyst activity', \is_array($activity) ? self::text($activity['displayName'] ?? null) : null);
        }
        foreach ($event['crossReference'] ?? [] as $reference) {
            if ('RHEA' === ($reference['databaseName'] ?? null)) {
                $add('rhea', 'Rhea reaction', 'RHEA:'.$reference['identifier']);
            }
        }
        $add('review_status', 'Review status', self::text($event['reviewStatus']['displayName'] ?? null));
        $add('inferred', 'Inferred from another species', isset($event['isInferred']) ? (bool) $event['isInferred'] : null);
        $add('in_disease', 'A disease event', isset($event['isInDisease']) ? (bool) $event['isInDisease'] : null);
        $add('released', 'First released', self::text($event['releaseDate'] ?? null));
        $add('last_updated', 'Last updated', self::text($event['lastUpdatedDate'] ?? null));
        $add('version', 'Stable identifier with its version', self::text($event['stIdVersion'] ?? null));
        $add('doi', 'DOI', self::text($event['doi'] ?? null));
        $add('references', 'Literature references', isset($event['literatureReference']) ? \count($event['literatureReference']) : null);
        $add('events', 'Sub-events', isset($event['hasEvent']) ? \count($event['hasEvent']) : null);

        $summary = [];
        foreach ($event['summation'] ?? [] as $summation) {
            if (null !== ($text = self::text($summation['text'] ?? null))) {
                $summary[] = $text;
            }
        }
        $label = self::text($event['displayName'] ?? null) ?? $id->value;
        $names = array_values(array_filter(array_map(static fn (mixed $n) => \is_string($n) ? self::text($n) : null, $event['name'] ?? []), static fn (?string $n) => null !== $n && $n !== $label));

        $related = [];
        $organisms = [];
        foreach ($event['species'] ?? [] as $species) {
            $organisms[] = Identifier::tryOf(Scheme::NCBI_TAXON, $species['taxId'] ?? null);
        }
        $related['organism'] = new Identifiers($organisms);
        $related['event'] = new Identifiers(array_map(static fn (mixed $e) => \is_array($e) ? Identifier::tryOf(Scheme::REACTOME, $e['stId'] ?? null) : null, $event['hasEvent'] ?? []));
        $related['ortholog'] = new Identifiers(array_map(static fn (mixed $e) => \is_array($e) ? Identifier::tryOf(Scheme::REACTOME, $e['stId'] ?? null) : null, $event['orthologousEvent'] ?? []));
        if (null !== $participants) {
            $proteins = [];
            $molecules = [];
            foreach ($participants as $participant) {
                foreach ($participant['refEntities'] ?? [] as $reference) {
                    $parsed = Identifier::parse((string) ($reference['stId'] ?? ''));
                    match ($parsed?->scheme) {
                        Scheme::UNIPROT => $proteins[] = $parsed,
                        Scheme::CHEBI => $molecules[] = $parsed,
                        default => null,
                    };
                }
            }
            $related['protein'] = new Identifiers($proteins);
            $related['molecule'] = new Identifiers($molecules);
        }
        $related = array_filter($related, static fn (Identifiers $ids) => \count($ids) > 0);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::BIOLOGY,
            kind: strtolower((string) preg_replace('~(?<=[a-z])(?=[A-Z])~', ' ', $class)),
            synonyms: $names,
            description: $summary ? implode("\n\n", $summary) : null,
            properties: $properties,
            url: $id->url(),
            image: match (true) {
                (bool) ($event['hasDiagram'] ?? false) => self::BASE_URI.'exporter/diagram/'.$id->value.'.png',
                \in_array($class, ['Reaction', 'BlackBoxEvent', 'Polymerisation', 'Depolymerisation', 'FailedReaction'], true) => self::BASE_URI.'exporter/reaction/'.$id->value.'.png',
                default => null,
            },
            source: 'reactome',
            related: $related,
        );
    }

    /** @param array<string, mixed> $entry a search entry */
    private function fromSearch(array $entry): ?Entity
    {
        $id = Identifier::tryOf(Scheme::REACTOME, $entry['stId'] ?? null);
        if (null === $id) {
            return null;
        }
        $properties = [];
        foreach ((array) ($entry['species'] ?? []) as $species) {
            $properties[] = new Property('species', 'Species', (string) $species, 'reactome');
        }
        foreach ($entry['compartmentNames'] ?? [] as $i => $compartment) {
            $accession = $entry['compartmentAccession'][$i] ?? null;
            $properties[] = new Property('compartment', 'Compartment', $compartment.(null === $accession ? '' : ' (GO:'.$accession.')'), 'reactome');
        }

        return new Entity(
            id: $id,
            label: self::text($entry['name'] ?? null) ?? $id->value,
            domain: Domain::BIOLOGY,
            kind: strtolower((string) preg_replace('~(?<=[a-z])(?=[A-Z])~', ' ', (string) ($entry['type'] ?? $entry['exactType'] ?? ''))) ?: null,
            description: self::text($entry['summation'] ?? null),
            properties: $properties,
            url: $id->url(),
            source: 'reactome',
        );
    }

    /** @param array<string, mixed> $event a light event (the mapping's) */
    private function light(array $event): ?Entity
    {
        $id = Identifier::tryOf(Scheme::REACTOME, $event['stId'] ?? null);
        if (null === $id) {
            return null;
        }
        $species = self::text($event['speciesName'] ?? null);

        return new Entity(
            id: $id,
            label: self::text($event['displayName'] ?? null) ?? $id->value,
            domain: Domain::BIOLOGY,
            kind: strtolower((string) preg_replace('~(?<=[a-z])(?=[A-Z])~', ' ', (string) ($event['schemaClass'] ?? $event['className'] ?? ''))) ?: null,
            properties: null === $species ? [] : [new Property('species', 'Species', $species, 'reactome')],
            url: $id->url(),
            source: 'reactome',
        );
    }

    /** A GO term as "cytosol (GO:0005829)". */
    private static function term(mixed $term): ?string
    {
        if (!\is_array($term) || null === ($name = self::text($term['displayName'] ?? $term['name'] ?? null))) {
            return null;
        }

        return isset($term['accession']) ? $name.' ('.($term['databaseName'] ?? 'GO').':'.$term['accession'].')' : $name;
    }

    /** The configured species as the mapping takes it: a taxon id ("9606") or a name. */
    private function taxon(): ?string
    {
        return match ($this->species) {
            'Homo sapiens' => '9606',
            default => $this->species,
        };
    }
}
