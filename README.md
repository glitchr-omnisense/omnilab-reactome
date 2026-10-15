# omnilab/reactome

Reactome for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a pathway or a reaction
by its stable identifier - `R-HSA-70171`, glycolysis; `R-HSA-70420`, a reaction of it - with its
names, species, summary, compartments, GO biological process, Rhea reaction, inputs, outputs and
catalyst, review status, release dates, DOI and diagram; its participants as related things (the
UniProt proteins and the ChEBI small molecules it involves), its organism, its sub-events and its
orthologous events in other species; a search by text; and the pathways or reactions of a protein
or a small molecule. Through Reactome's Content Service, no key.

```php
$reactome = (new ReactomeSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$pathway = $reactome->entity('R-HSA-70171');
$pathway->label;                          // Glycolysis
$pathway->value('compartment');           // cytosol (GO:0005829)
$pathway->value('go_biological_process'); // canonical glycolysis (GO:0061621)
$pathway->related('protein')->keys();     // uniprot:P30153, ... (79)
$pathway->related('molecule')->keys();    // chebi:15377, ... (32)
$pathway->related('event')->keys();       // reactome:R-HSA-170822, reactome:R-HSA-70420, ... (16)
$reactome->search(new Query('glycolysis', limit: 3));   // Glycolysis, Regulation of glycolysis by..., Glucose metabolism (76 matches)
$reactome->pathways('P68871');            // hemoglobin beta's ten pathways (outside the contract)
```

```yaml
omnilab:
    sources:
        reactome:
            factory: reactome
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                species: 'Homo sapiens'        # what a search and pathways() keep; 'all': every species
                participants: true             # entity() asks for the participants (a second call)
```

A pathway is named in the core's `reactome` scheme. With Wikidata configured too, the
`Collector` follows a pathway from Wikidata's Reactome ID (P3937) into Reactome
(`$collector->collect('wikidata', 'Q45317172')`, glycolysis); a protein's pathways come from
`pathways()`.

## Terms of use

Reactome's data are under [CC0](https://creativecommons.org/publicdomain/zero/1.0/), its other
content (text, illustrations) under CC BY 4.0 ([reactome.org/license](https://reactome.org/license));
attribution is encouraged: cite the current paper (The Reactome Knowledgebase 2026, Nucleic Acids
Res. 2025, [10.1093/nar/gkaf1223](https://doi.org/10.1093/nar/gkaf1223)). No rate is published.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Reactome. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
