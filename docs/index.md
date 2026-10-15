# omnilab/reactome

## Installation

```sh
composer require omnilab/reactome
```

```php
use Omnilab\Reactome\ReactomeSourceFactory;

$reactome = (new ReactomeSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'species' => 'Homo sapiens',       // optional: what a search and pathways() keep; 'all': every species
    'participants' => true,            // optional: entity() asks for the participants too
]);
```

## The scheme

A Reactome event is named by its stable identifier in the core's `reactome` scheme
(`Scheme::REACTOME`): `R-` then a species' three letters (`HSA`, `MMU`; `ALL` for what no species
holds) then a number, its version left out (`R-HSA-70171.10` is `R-HSA-70171`), read from its page
(`https://reactome.org/content/detail/R-HSA-70171`), from `reactome:` and from its shape alone.
Wikidata holds Reactome ids in P3937 ("Reactome ID", on 43,834 items on 2026-10-15), which
omnilab/wikidata reads into that scheme.

## Which API: the Content Service

`https://reactome.org/ContentService/` (release 97 on 2026-10-15, its OpenAPI at `v3/api-docs`):

| Method | Calls |
|---|---|
| `entity()` | `data/query/{stId}` (the event), then `data/participants/{stId}` (its physical entities and their reference entities: UniProt, ChEBI) unless `participants` is false |
| `search()` | `search/query?query=...&species=...&types=Pathway&types=Reaction&cluster=false&Start row=...&rows=...` - the paging parameter is named `Start row`, with its space (`start` is ignored: the same first page comes back); a 404 is "No entries found" |
| `pathways()` | outside the contract: `data/mapping/UniProt/{accession}/pathways` (or `/reactions`), `data/mapping/ChEBI/{id}/pathways`, `species=9606` for Homo sapiens |

An identifier of another base is refused before any call; unknown is `null` (404); an identifier
of a physical entity (`R-ALL-113592`, ATP in the cytosol; a complex, a protein) is `null` too:
entity() reads events (pathways, reactions, black-box events, polymerisations...), and asks no
participants for what it does not read.

## What an entity holds

`id` (`reactome:R-HSA-70171`), `label` (its display name), `domain` biology, `kind` (Reactome's
class, lower case: `pathway`, `reaction`, `black box event`...), `synonyms` (its other names: a
reaction's equation, `alpha-D-Glucose + ATP => alpha-D-glucose 6-phosphate + ADP`), `description`
(its summation, tags stripped), `url` (its page), `image` (its diagram when it has one, a
reaction's picture), and these properties:

| Key | Content Service | Value |
|---|---|---|
| `species` | speciesName | text |
| `compartment` | compartment (one property each) | `cytosol (GO:0005829)` |
| `go_biological_process` | goBiologicalProcess | `canonical glycolysis (GO:0061621)` |
| `category` | a reaction's category | `transition`, `binding`, `dissociation`... |
| `input`, `output` (one each), `catalyst_activity` | input, output, catalystActivity | `ATP [cytosol]`... |
| `rhea` | crossReference of Rhea | `RHEA:17826` |
| `review_status` | reviewStatus | `five stars` |
| `inferred`, `in_disease` | isInferred, isInDisease | boolean |
| `released`, `last_updated` | releaseDate, lastUpdatedDate | date as text |
| `version` | stIdVersion | `R-HSA-70171.10` |
| `doi` | doi (some pathways) | text |
| `references`, `events` | literatureReference, hasEvent | how many |

`identifiers`: its own alone (no other base names a pathway). `related`: `organism`
(`ncbi.taxon:9606`), `event` (its sub-pathways and reactions), `ortholog` (the same event inferred
in other species: `R-MMU-70171`...), `protein` (the UniProt accessions of its participants, every
member of a set or a complex), `molecule` (the ChEBI ids of its small molecules). The `Collector`
follows none of them; `$collector->collect('uniprot', $pathway->related('protein')->get(Scheme::UNIPROT))`
reads one.

## From UniProt to Reactome

UniProt gives a protein; `pathways()` gives the pathways it takes part in (the lowest-level ones),
`entity()` reads each:

```php
$protein = $uniprot->entity('P68871');                 // hemoglobin beta
foreach ($reactome->pathways($protein->id) as $pathway) {
    echo $pathway->id, ' ', $pathway->label, "\n";    // 10 on 2026-10-15
}
$reactome->pathways($protein->id, reactions: true);    // 28 reactions
```

```
reactome:R-HSA-1237044 Erythrocytes take up carbon dioxide and release oxygen
reactome:R-HSA-1247673 Erythrocytes take up oxygen and release carbon dioxide
reactome:R-HSA-2168880 Scavenging of heme from plasma
reactome:R-HSA-6798695 Neutrophil degranulation
reactome:R-HSA-9613829 Chaperone Mediated Autophagy
reactome:R-HSA-9615710 Late endosomal microautophagy
reactome:R-HSA-9707564 Cytoprotection by HMOX1
reactome:R-HSA-9707616 Heme signaling
reactome:R-HSA-983231 Factors involved in megakaryocyte development and platelet production
reactome:R-HSA-9927020 Heme assimilation
```

Not a `Collector` path: a protein is not its pathways (they are other things, `related` at most),
so no identifier of a protein names a pathway.

## The Collector: glycolysis from Wikidata

Wikidata's item for a pathway carries its Reactome ID (P3937). Against the real services on
2026-10-15:

```php
$collector->collect('wikidata', 'Q45317172', 1, ['reactome']);
```

4 calls in 2.0 s - Wikidata's two (the item, the labels of its classes), Reactome's two (the event,
its participants) - and `sources` = wikidata, reactome, identifiers `wikidata:Q45317172`,
`reactome:R-HSA-70171`:

```
species                Homo sapiens [reactome]
compartment            cytosol (GO:0005829) [reactome]
go_biological_process  canonical glycolysis (GO:0061621) [reactome]
review_status          five stars [reactome]
```

## What the Content Service does not give here

The physical entities (complexes, sets, proteins as Reactome models them), the diagrams' layouts,
the analysis service (over-representation of a list of genes), the interactors, the literature
references themselves (counted only), the disease variants, the hierarchy above an event (its
parent pathways: `data/pathways/low/...`, not asked), and the orthologous events' content.

## Verified, and not

Against the real service on 2026-10-15 (release 97): glycolysis (16 events, 79 proteins, 32
molecules, 14 orthologous events), the reaction R-HSA-70420 by its id, its versioned id, its page
and `reactome:`; ATP in the cytosol (a physical entity: null); an unknown id (404); the search for
"glycolysis" in two pages of three (76 matches), for a text nothing matches (404); hemoglobin
beta's ten pathways and 28 reactions; glucose's 27 pathways (ChEBI 4167; ATP, ChEBI 30616, takes
part in 827); the Collector's walk from Wikidata above. The fixtures in `Tests/Fixtures/` are its answers. Each call took 0.2 to 1.8 s.

Not verified: a rate limit (none published, none met); the service's behaviour with `species:
'all'` on a search (asked with Homo sapiens only).
