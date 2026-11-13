# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab Reactome: a pathway or a reaction by its stable identifier (R-HSA-70171) - its names,
  species, summary, compartments, GO biological process, Rhea reaction, inputs, outputs and
  catalysts, review status, release dates, DOI and diagram - its participants (UniProt proteins,
  ChEBI small molecules), organism, sub-events and orthologous events as related things, a search by
  text, and the pathways of a protein or a molecule (UniProt, ChEBI), through Reactome's Content
  Service, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/reactome` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
