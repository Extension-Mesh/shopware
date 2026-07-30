<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Storefront;

use ExtensionMesh\Shopware\Service\StorefrontDownloadCatalog;
use ExtensionMesh\Shopware\Service\ReleaseNotesRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ExtensionMeshDownloadTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly StorefrontDownloadCatalog $catalog,
        private readonly ReleaseNotesRenderer $releaseNotes
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'extension_mesh_download_catalog',
                $this->catalog->group(...)
            ),
            new TwigFunction(
                'extension_mesh_release_notes',
                $this->releaseNotes->render(...),
                ['is_safe' => ['html']]
            ),
        ];
    }
}
