<?php
namespace Timeline\Site\BlockLayout;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Stdlib\ErrorStore;
use Zend\View\Renderer\PhpRenderer;
use Zend\Form\Form;
use Timeline\Form\TimelineBlock;

class Timeline extends AbstractBlockLayout
{
    /**
     * @var ApiManager
     */
    protected $apiManager;

    /**
     * @var bool
     */
    protected $useExternal;

    public function __construct(ApiManager $apiManager, $useExternal)
    {
        $this->apiManager = $apiManager;
        $this->useExternal = $useExternal;
    }

    public function getLabel()
    {
        return 'Timeline'; // @translate
    }

    public function prepareForm(PhpRenderer $view)
    {
        $view->headLink()->prependStylesheet($view->assetUrl('css/advanced-search.css', 'Omeka'));
        $view->headScript()->appendFile($view->assetUrl('js/timeline-item-pool.js', 'Timeline'));
    }

    public function form(PhpRenderer $view, SiteRepresentation $site,
        SitePageRepresentation $page = null, SitePageBlockRepresentation $block = null
    ) {
        $data = $block ? $block->data() : [];

        $form = new TimelineBlock();
        $form->setApiManager($this->apiManager);
        $form->init();

        $addedBlock = empty($data);
        if ($addedBlock) {
            $data['args'] = $view->setting('timeline_defaults');
            $data['item_pool'] = $site->itemPool();
            $itemCount = null;
        } else {
            $itemCount = $this->itemCount($data);
        }

        $form->setData([
            'o:block[__blockIndex__][o:data][args]' => $data['args'],
            'o:block[__blockIndex__][o:data][item_pool]' => $data['item_pool'],
        ]);

        return $view->partial(
            'common/block-layout/timeline-form',
            [
                'form' => $form,
                'data' => $data,
                'itemCount' => $itemCount,
            ]
        );

        return $view->blockTimelineForm($block);
    }

    public function prepareRender(PhpRenderer $view)
    {
        $library = $view->setting('timeline_library');
        switch ($library) {
            case 'knightlab':
                $view->headLink()->appendStylesheet('//cdn.knightlab.com/libs/timeline3/latest/css/timeline.css');
                $view->headScript()->appendFile('//cdn.knightlab.com/libs/timeline3/latest/js/timeline.js');
                break;

            case 'simile':
            default:
                $view->headLink()->appendStylesheet($view->assetUrl('css/timeline.css', 'Timeline'));
                $view->headScript()->appendFile($view->assetUrl('js/timeline.js', 'Timeline'));
                if ($this->useExternal) {
                    $view->headScript()->appendFile('//api.simile-widgets.org/timeline/2.3.1/timeline-api.js?bundle=true');
                    $view->headScript()->appendScript('SimileAjax.History.enabled = false; window.jQuery = SimileAjax.jQuery;');
                } else {
                    $timelineVariables = 'Timeline_ajax_url="' . $view->assetUrl('js/simile/ajax-api/simile-ajax-api.js', 'Timeline') . '";' . PHP_EOL;
                    $timelineVariables .= 'Timeline_urlPrefix="' . dirname($view->assetUrl('js/simile/timeline-api/timeline-api.js', 'Timeline')) . '/";' . PHP_EOL;
                    $timelineVariables .= 'Timeline_parameters="bundle=true";';
                    $view->headScript()->appendScript($timelineVariables);
                    $view->headScript()->appendFile($view->assetUrl('js/simile/timeline-api/timeline-api.js', 'Timeline'));
                    $view->headScript()->appendScript('SimileAjax.History.enabled = false; // window.jQuery = SimileAjax.jQuery;');
                }
                break;
        }
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $library = $view->setting('timeline_library');
        return $view->partial(
            'common/block-layout/timeline_' . $library,
            [
                'blockId' => $block->id(),
                'data' => $block->data(),
            ]
        );
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $data = $block->getData();

        $data['item_pool'] = json_decode($data['item_pool'], true) ?: [];

        $data['args']['viewer'] = trim($data['args']['viewer']);
        if ($data['args']['viewer'] === '') {
            $data['args']['viewer'] = '{}';
        }

        $vocabulary = strtok($data['args']['item_date'], ':');
        $name = strtok(':');
        $property = $this->apiManager
            ->search('properties', ['vocabulary_prefix' => $vocabulary, 'local_name' => $name])
            ->getContent();
        $data['args']['item_date_id'] = (string) $property[0]->id();

        $block->setData($data);
    }

    /**
     * Helper to get the item count for the item pool, filtered of empty dates.
     *
     * @param array $data
     * @return int
     */
    protected function itemCount($data)
    {
        $params = $data['item_pool'];
        // Add the param for the date: return only if not empty.
        $params['has_property'][$data['args']['item_date_id']] = 1;
        $params['limit'] = 0;
        $itemCount = $this->apiManager->search('items', $params)->getTotalResults();
        return $itemCount;
    }
}
