# yii-elastic

[![Packagist package][ico-packagist]][link-packagist]
[![License][ico-license]](LICENSE.md)

Set of tools for working with elasticsearch using [ Elastica ](anasAsh/Yii-Elastica) for those stuck with projects in Yii 1.1. Includes a CActiveDataProvider compatible data provider, a CActiveRecord behavior and an elasticsearch query helper.

Apart for enabling an application for speedy searches, yii-elastic also enables it for searches inside relations with near zero configuration.

## Features
* No elasticsearch background required, a very simple installation is all that is needed (you don't even need to manually create an index or type in elasticsearch)
* ElasticActiveRecordBehavior
  * Automatically indexed (documents are added and deleted from elasticsearch when a record is inserted/updated/deleted)
  * Automatic indexing of relations and even their relations at any depth (minimal configuration required)
* ElasticDataProvider
  * Compatible with CGridView, CListView, etc. Just use ```$model->elasticSearch()``` instead of ```$model->search()```
  * Fully compatible with CActiveDataProvider, all pagination and sorting is supported
  * Search supports all the operands that you expect it to (all that are supported by ```CDbCriteria->compare())```, >, <, >=, <>, etc)
  * Returns CActiveRecord[] just like CActiveDataProvider for maximum compatibility (see caveats)
* ElasticQueryHelper
  * Build elasticsearch queries (implements CDbCriteria->compare(), works for relations)
  * Effortlessly search using relations' fields (```category.products.price>10``` just works)

## Notes
This is something I put together because of a a project I am working on and by no means do I consider it a complete abstraction for elasticsearch, not even close. Though, it should cover the needs for most projects and you can work with elasticsearch in Yii comfortably.

No unit tests have been implemented, they might be added if I need to add more features.

No composer support, Yii 1.1 does directly support it.

Feel free to help out to add functionality if you feel like it, I'd be more than grateful for any feedback/help.

## Caveats
* This will only work for ```CActiveRecord``` implementations with one primary key (any type), composite primary keys are not (and will not be) supported.
* ```ElasticDataProvider``` uses elasticsearch to search but what it returns is ```CActiveRecord[]```, NOT an array of elasticsearch documents. It fetches the primary keys for the matched records and in turn queries by primary key in order to return ```CActiveRecord[]``` for maximum compatibility. This can be seen as a drawback or advantage, to me it is a major advantage as I use SQL for persistence, only need elastic for very specific tasks, and prefer to work with ```CActiveRecord``` instances which other Yii 1.1 widgets/extensions/components/plugins like the most.
* Searching on relation fields currently only works for a full match ("imba" will not match "imbalanced" as you might expect)

## Installation

```
$ composer config repositories.yii-elastic git https://github.com/dimvic/yii-elastic
$ composer require dimvic/yii-elastic:dev-master
$ composer update
```

Then Add to your configuration:
```php
return [
	...
	'components' => [
		'elastica' => [
			'class' => 'extensions.yii-elastic.Elastica',
			'host' => '127.0.0.1',
			'port' => '9200',
			'debug' => YII_DEBUG,
		],
		...
	],
	...
];
```

## Getting started

### Attach ```ElasticActiveRecordBehavior``` to a ```CActiveRecord```:
```php
class Post extends CActiveRecord
{
    ...
	public function Behaviors()
	{
		return array_replace(parent::behaviors(), [
            [
                'class'=>'ext.yii-elastic.ElasticActiveRecordBehavior',
                'elastic_index'=>null,   //defaults to parsing db name from $this->getDbConnection()
                'elastic_type'=>null,    //defaults to $model->tableName()
                'elastic_raw_cols'=>null,//the columns that will be used for aggregations, defaults to ['caption', 'slug', 'label', 'name']
                'elastic_relations'=>[   //the relations you want indexed, can be nested to any depth
                    'author',
                    'author.group',
                ],
            ],
            ...
		]);
	}
    ...
]
```

### Index the model's data in elastic
Beware! This will create the index and type in elasticsearch, and it will delete them first if they already exist! It will also index all data, so have a look at the parameters the method accepts if you intend to run it on big data sets.
```php
Post::model()->elasticRebuild();
```

### Ready
In your controller:
```php
public function actionGrid() {
    $model = new Post('search');

    $this->render('grid', [
        'model' => $model,
    ]);
}
```

In your view:
```php
$this->widget('zii.widgets.grid.CGridView', [
    'dataProvider'=>$model->elasticSearch(),
    'filter'=>$model,
    'columns' => [
        ['name'=>'id'],
        ['name'=>'title'],
        ['name'=>'author.name', 'filter'=>$model->nestedFilterInput('author.name')],
        ['name'=>'author.group.name', 'filter'=>$model->nestedFilterInput('author.group.name')],
    ],
    'ajaxUpdate'=>false,
]);
```

And you can already search by the post author's group name.

Or you might prefer to have it like this:
```php
$this->widget('zii.widgets.grid.CGridView', [
    'dataProvider'=>$model->elasticSearch(),
    'filter'=>$model,
    'columns' => [
        ['name'=>'id'],
        ['name'=>'title'],
        ['name'=>'author.name', 'filter'=>$model->nestedFilterInput('author.name')],
        [
            'name'=>'author.group.id',
            'value'=>'$data->author->group->name'
            'filter'=>CHtml::listData(Group::model()->findall(), 'id', 'name'),
        ],
    ],
    'ajaxUpdate'=>false,
]);
```

## Usage
Although the default functionality should be enough for most cases, you can handle more complex scenarios with ease.

You will probably want to have a look at:
* ```ElasticActiveRecordBehavior->elasticSearch()``` if you want to run custom queries
* ```ElasticActiveRecordBehavior->createElasticType()``` and ```ElasticActiveRecordBehavior->elasticProperties()``` if you want to index your data in a specific way (you will need to alter ```ElasticActiveRecordBehavior->createElasticDocument()``` which prepares the documents as well)
* ```ElasticQueryHelper```, where you can get an idea of how queries are build and how to supplement existing ones with something more fancy.
Playing with the parameters of the methods should be enough for most cases, overloading is always an option.

```ElasticActiveDataProvider->getResultSet()``` will give you access to the ```\Elastica\ResultSet``` for every query, in case you need more insight on the search you perform.

## Thanks
* [ruflin/Elastica](https://github.com/ruflin/Elastica)
* [anasAsh/Yii-Elastica](https://github.com/anasAsh/Yii-Elastica)

## Todo
* Add ```$model->relations()``` to index by default
* ElasticaQueryHelper::compare() dates support
* Create a schema cache for the relational fields, as they will always be searched for exact matches ATM
* Support aggregations for model and relation fields
* Documentation

[ico-packagist]: https://img.shields.io/badge/packagist-dev-lightgrey.svg?style=flat-square
[ico-license]: https://img.shields.io/packagist/l/dimvic/yii-elastic.svg?style=flat-square
[link-packagist]: https://packagist.org/packages/dimvic/yii-elastic
