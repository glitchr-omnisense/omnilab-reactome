<?php

namespace Omnilab\Reactome;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Reactome: pathways and reactions by stable identifier, their
 * participants (UniProt proteins, ChEBI molecules), a search, and the
 * pathways of a protein - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     species: 'Homo sapiens'          # what a search and pathways() keep; null: every species
 *     participants: true               # entity() asks for the participants too (a second call)
 *     throttle: 0                      # seconds between two calls: Reactome publishes no rate
 *     base_uri: 'https://reactome.org/ContentService/'
 */
final class ReactomeSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'reactome',
            'omnilab.required_options' => [],
            'species' => 'Homo sapiens',
            'participants' => true,
            'throttle' => 0.0,
            'base_uri' => ReactomeSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $species = $c->get('species');

        return new ReactomeSource(
            $this->http ?? HttpClient::create(),
            \in_array($species, [null, '', 'all', false], true) ? null : (string) $species,
            (bool) $c->get('participants'),
            (string) $c->get('base_uri', ReactomeSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
