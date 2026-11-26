<?php

declare(strict_types=1);

namespace Supertext\Polylang\Sdk\V1;

/**
 * A correction a reviewer made in Supertext Localization Review that the integration hasn't
 * written into the CMS yet (Client::pendingCorrections(); spec/corrections.json).
 */
final class Correction
{
    /**
     * @param string      $id          the review service's id of this place (send back with acknowledgeCorrections())
     * @param string      $segmentId   the data-st-id the integration gave the segment (its own reference to the field)
     * @param string|null $sourceLang  null: the source language was detected
     * @param string      $politeness  Politeness::DEFAULT, MORE or LESS
     * @param string      $previousHtml what the integration received for this place last: check the field still holds it before overwriting an editor's change
     * @param string      $html        the corrected translation: write this into the field
     * @param string      $status      "corrected", "approved", …
     * @param string      $correctedAt ISO time of the latest change
     * @param string      $version     changes with every new correction: acknowledge the version you wrote
     */
    public function __construct(
        public readonly string $id,
        public readonly string $segmentId,
        public readonly ?string $sourceLang,
        public readonly string $targetLang,
        public readonly string $politeness,
        public readonly string $sourceHtml,
        public readonly string $previousHtml,
        public readonly string $html,
        public readonly string $status,
        public readonly string $correctedAt,
        public readonly string $version,
    ) {
    }
}
