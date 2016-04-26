<?php

namespace DevGroup\TagDependencyHelper;

use Yii;
use yii\caching\TagDependency;
use yii\db\ActiveRecord;

/**
 * TagDependencyTrait features:
 * - retrieving common and object tags
 * - configuring cache component(through overriding getTagDependencyCacheComponent)
 * - configuring composite tags(through overriding cacheCompositeTagFields)
 * - Identity Map pattern support
 */
trait TagDependencyTrait
{
    /** @var array IdentityMap pattern support */
    public static $identityMap = [];

    /**
     * @return \yii\caching\Cache
     */
    public function getTagDependencyCacheComponent()
    {
        return Yii::$app->cache;
    }

    /**
     * Returns common tag name for model instance
     * @return string tag name
     */
    public static function commonTag()
    {
        /** @var \yii\db\ActiveRecord $this */
        return NamingHelper::getCommonTag(static::className());
    }

    /**
     * Returns object tag name including it's id
     * @param array Changed fields from Update Event
     * @return string tag name
     */
    public function objectTag($oldfields = [])
    {
        /** @var \yii\db\ActiveRecord $this */
        $primary_key;
        if (count($this->primaryKey()) == 1)
        {
            $key = $this->primaryKey()[0];
            $primary_key = isset($oldfields[$key]) ? $oldfields[$key] : $this->$key;
        } else {
            $primary_key = [];
            foreach ($this->primaryKey() as $key)
            {
                $primary_key[$key] = isset($oldfields[$key]) ? $oldfields[$key] : $this->$key;
            }
        }
        return NamingHelper::getObjectTag($this->className(), $primary_key);
    }

    /**
     * Returns composite tags name including fields
     * @param array Changed fields from Update Event
     * @return array tag names
     */
    public function objectCompositeTag($oldfields = [])
    {
        /** @var \yii\db\ActiveRecord|TagDependencyTrait $this */
        $cacheFields = $this->cacheCompositeTagFields();

        if(empty($cacheFields)) {
            return [];
        }

        $tags = [];

        foreach ($cacheFields as $tagFields) {
            $tag = [];
            $changed = false;

            foreach ($tagFields as $tagField) {
                $tag[$tagField] = $this->$tagField;
                $changed |= isset($oldfields[$tagField]);
            }

            $tags[] = NamingHelper::getCompositeTag($this->className(), $tag);

            if ($changed) {
                $tag = [];
                foreach ($tagFields as $tagField) {
                    $tag[$tagField] = isset($oldfields[$tagField]) ? $oldfields[$tagField] : $this->$tagField;
                }
                $tags[] = NamingHelper::getCompositeTag($this->className(), $tag);
            }
        }

        return $tags;
    }

    /**
     * Specific fields from model for build composite tags for invalidate
     * Example:
     * return [
     *  ['field1', 'field2'],
     *  ['field1', 'field2', 'field3'],
     * ];
     * @return array
     */
    protected function cacheCompositeTagFields()
    {
        return [];
    }

    /**
     * Finds or creates new model using or not using cache(objectTag is applied)
     * @param string|int $id ID of model to find
     * @param bool $createIfEmptyId Create new model instance(record) if id is empty
     * @param bool $useCache Use cache
     * @param int $cacheLifetime Cache lifetime in seconds
     * @param bool|\Exception $throwException False or exception instance to throw if model not found or (empty id AND createIfEmptyId==false)
     * @param bool $useIdentityMap True if we want to use identity map
     * @return \yii\db\ActiveRecord|null|self|TagDependencyTrait
     * @throws \Exception
     */
    public static function loadModel(
        $id,
        $createIfEmptyId = false,
        $useCache = true,
        $cacheLifetime = 86400,
        $throwException = false,
        $useIdentityMap = false
    ) {
        /** @var \yii\db\ActiveRecord|TagDependencyTrait $model */
        $model = null;
        if (empty($id)) {
            if ($createIfEmptyId === true) {
                $model = new static;
            } else {
                if ($throwException !== false) {
                    throw $throwException;
                } else {
                    return null;
                }
            }
        } elseif ($useIdentityMap === true) {
            if (isset(static::$identityMap[$id])) {
                return static::$identityMap[$id];
            }
        }

        if ($useCache === true && $model===null) {
            $model = Yii::$app->cache->get(static::className() . ":" . $id);
        }
        if (!is_object($model)) {
            $model = static::findOne($id);

            if ($model !== null) {
                if ($useIdentityMap === true) {
                    static::$identityMap[$model->id] = &$model;
                }
                if ($useCache === true) {
                    Yii::$app->cache->set(
                        static::className() . ":" . $id,
                        $model,
                        $cacheLifetime,
                        new TagDependency([
                            'tags' => $model->objectTag(),
                        ])
                    );
                }
            }
        }
        if (!is_object($model)) {
            if ($throwException) {
                throw $throwException;
            } else {
                return null;
            }
        }
        return $model;
    }

    /**
     * Invalidate model tags.
     * @param yii\db\AfterSaveEvent when called as an event handler.
     * @return bool
     */
    public function invalidateTags($event = null)
    {
        /** @var TagDependencyTrait $this */
        \yii\caching\TagDependency::invalidate(
            $this->getTagDependencyCacheComponent(),
            [
                static::commonTag(),
                $this->objectTag($event != null && $event->name == ActiveRecord::EVENT_AFTER_UPDATE ? $event->changedAttributes : [])
            ]
        );

        if (!empty($this->cacheCompositeTagFields())) {
            \yii\caching\TagDependency::invalidate(
                $this->getTagDependencyCacheComponent(),
                $this->objectCompositeTag($event != null && $event->name == ActiveRecord::EVENT_AFTER_UPDATE ? $event->changedAttributes : [])
            );
        }

        return true;
    }

}
