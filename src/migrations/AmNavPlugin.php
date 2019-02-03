<?php
namespace verbb\navigation\migrations;

use verbb\navigation\Navigation;
use verbb\navigation\elements\Node;
use verbb\navigation\models\Nav as NavModel;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;

class AmNavPlugin extends Migration
{
    private $processedNodes = [];

    // Public Methods
    // =========================================================================

    public function safeUp()
    {
        $AmNavs = (new Query())
            ->select(['*'])
            ->from(['{{%amnav_navs}}'])
            ->all();

        foreach ($AmNavs as $key => $AmNav) {
            $nav = Navigation::$plugin->navs->getNavByHandle($AmNav['handle']);

            if (!$nav) {
                $nav = new NavModel();
            }

            $nav->name = $AmNav['name'];
            $nav->handle = $AmNav['handle'];

            $settings = Json::decode($AmNav['settings']);
            $nav->maxLevels = $settings['maxLevels'] ?? '';
            $nav->sortOrder = $key;

            if (!Navigation::$plugin->navs->saveNav($nav)) {
                continue;
            }

            $AmNodes = (new Query())
                ->select(['*'])
                ->from(['{{%amnav_nodes}}'])
                ->where(['navId' => $AmNav['id']])
                ->orderBy('parentId ASC, order ASC')
                ->all();

            foreach ($AmNodes as $key => $AmNode) {
                $node = new Node();

                $node->title = $AmNode['name'];
                $node->enabled = $AmNode['enabled'];
                $node->elementId = $AmNode['elementId'];
                $node->navId = $nav->id;
                $node->url = $AmNode['url'];
                $node->classes = $AmNode['listClass'];
                $node->newWindow = $AmNode['blank'];

                $locale = $AmNode['locale'];
                $site = Craft::$app->getSites()->getSiteByHandle($locale);

                if ($site) {
                    $node->siteId = $site->id;
                }

                if ($AmNode['elementType'] === 'Entry') {
                   $node->type = \craft\elements\Entry::class;
                } else if ($AmNode['elementType'] === 'Category') {
                   $node->type = \craft\elements\Category::class;
                } else if ($AmNode['elementType'] === 'Asset') {
                   $node->type = \craft\elements\Asset::class;
                }

                if (Craft::$app->getElements()->saveElement($node)) {
                    $this->processedNodes[$AmNode['id']] = [
                        'oldParent' => $AmNode['parentId'],
                        'newNode' => $node->id,
                    ];
                }
            }
        }

        // We have to update all parent's after all nodes are in, otherwise we can get issues (trying to set parent
        // of a node that might not exist yet)
        foreach ($this->processedNodes as $nodeInfo) {
            $newParent = $this->processedNodes[$nodeInfo['oldParent']] ?? null;

            if ($newParent) {
                $node = Navigation::$plugin->nodes->getNodeById($nodeInfo['newNode']);
                $node->newParentId = $newParent['newNode'];

                Craft::$app->getElements()->saveElement($node);
            }
        }

        return true;
    }

    public function safeDown()
    {
        return false;
    }
}
