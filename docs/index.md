# omnilab/reactome

Reactome's Content Service (`https://reactome.org/ContentService/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): pathways and reactions by stable
id (`R-HSA-70171`) - species, compartments, GO process, inputs and outputs, Rhea - with their UniProt
proteins, ChEBI molecules, organism, sub-events and orthologous events as related things, as
entities; a search; outside the contract, the pathways of a protein or a molecule. No key.

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

## Credentials

None: Reactome's Content Service takes no key and no account.

## Options

The factory's options, with the defaults it applies (`ReactomeSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `species` | `?string` | `Homo sapiens` | no | the species a search and `pathways()` keep (`Homo sapiens`, or a taxon id for `pathways()`); null, empty or `all`: every species |
| `participants` | `bool` | `true` | no | `entity()` asks for the participants too (a second call; thousands for a top-level pathway): the `protein` and `molecule` relations |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: Reactome publishes no rate |
| `base_uri` | `string` | `https://reactome.org/ContentService/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | an event by its stable id (`R-HSA-70171`, versioned or not, `reactome:`, its page): two calls (one with `participants` false); a physical entity is null; another base refused before any call |
| `search` | yes | the Content Service's search over pathways and reactions, in the configured species, 100 a page at most, with a `total`; a 404 is no match |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no | the image is the event's diagram, or a reaction's picture: not a structure |
| `cross_references` | no | none: no other base names a pathway; its participants, sub-events, orthologs and organism are `related` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Reactome\ReactomeSource`: `pathways(Identifier|string
$participant, bool $reactions = false): list<Entity>` - the lowest-level pathways (or the
reactions) a UniProt protein or a ChEBI molecule takes part in ([below](#from-uniprot-to-reactome)).

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Reactome\ReactomeSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$reactome = (new ReactomeSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$glycolysis = $reactome->entity('R-HSA-70171');
printf("%s %s (%s): %s, %d events, %d proteins, %d molecules, %d orthologous events\n", $glycolysis->id->display(), $glycolysis->label, $glycolysis->kind, $glycolysis->value('review_status'), count($glycolysis->related('event')), count($glycolysis->related('protein')), count($glycolysis->related('molecule')), count($glycolysis->related('ortholog')));

$page = $reactome->search(new Query('glycolysis', limit: 3));
printf("%d matches for \"glycolysis\"; the first %d:\n", $page->total, count($page));
foreach ($page as $event) {
    echo '  ', $event->id->display(), '  ', $event->label, '  (', $event->kind, ")\n";
}
printf("hemoglobin beta takes part in %d pathways\n", count($reactome->pathways('uniprot:P68871')));
```

```
R-HSA-70171 Glycolysis (pathway): five stars, 16 events, 79 proteins, 32 molecules, 14 orthologous events
76 matches for "glycolysis"; the first 3:
  R-HSA-70171  Glycolysis  (pathway)
  R-HSA-9634600  Regulation of glycolysis by fructose 2,6-bisphosphate metabolism  (pathway)
  R-HSA-70326  Glucose metabolism  (pathway)
hemoglobin beta takes part in 10 pathways
```

(as answered on 2026-10-27: four calls)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        reactome: { factory: reactome, options: { mailto: '%env(OMNILAB_MAILTO)%', species: 'Homo sapiens', participants: true } }
```

`pathways()` is a method of `ReactomeSource`, not of the interface the bundle injects: check the
instance before calling it.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Reactome's own: a search that matches nothing is its 404, an empty page; an unknown id its 404,
null. Before any call: an identifier of another base given to `entity()`, or anything but a
UniProt accession or a ChEBI id given to `pathways()` (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15 (release 97): glycolysis (16 events, 79 proteins, 32
molecules, 14 orthologous events), the reaction R-HSA-70420 by its id, its versioned id, its page
and `reactome:`; ATP in the cytosol (a physical entity: null); an unknown id (404); the search for
"glycolysis" in two pages of three (76 matches), for a text nothing matches (404); hemoglobin
beta's ten pathways and 28 reactions; glucose's 27 pathways (ChEBI 4167; ATP, ChEBI 30616, takes
part in 827); the Collector's walk from Wikidata above. The fixtures in `Tests/Fixtures/` are its answers. Each call took 0.2 to 1.8 s.

Again on 2026-10-27: glycolysis (16 events, 79 proteins, 32 molecules), the search for
"glycolysis" (76 matches), hemoglobin beta's ten pathways (the example above).

Not verified: a rate limit (none published, none met); the service's behaviour with `species:
'all'` on a search (asked with Homo sapiens only).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Reactome. Its data are under [CC0](https://creativecommons.org/publicdomain/zero/1.0/),
its other content under CC BY 4.0, and attribution is encouraged (The Reactome Knowledgebase 2026,
Nucleic Acids Res. 2025, 10.1093/nar/gkaf1223), as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-reactome#terms-of-use) says. Whoever
runs the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-reactome#responsibility) section.
