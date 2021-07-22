# PHP Record
An arbitrarily deep array that tracks changes, emits events,

Intended to be an observable that matches a database record, allowing the handling of a record like an array, keeping track of changes, handling deep data structures, and allowing listeners to react to change events.

(Similar to SplSubject, but because SplSubject uses pointless SplObserver, SplSubject is not implemented)




# Use

## Basic
Tracking arbitrarily deep structures
```php

$data = [
	'id' => 1,
	'name' => 'bob',
	'children' => [
		['name' => 'sue']
	]
];

$record = new \Grithin\Record($data);
$record['name'] = 'bill';

$record->changes(); #> ['name'=>'bill']

$record->apply(); # or alias $record->save();

$record->changes(); #> []

$record['children'][] = ['name'=>'jan'];
$record->changes();
/*>
{"children": {
        "1": {
            "name": "jan"}}}
*/
unset($record['children'][0]);
$record->changes();
/*>
{"children": [
        {"_class": "Grithin\\MissingValue"},
        {"name": "jan"}]}
*/
```

## Getter, Setter, And Events

```php

# Lets make a mock database
$database_records = [
	1=>[
		'id'=>1,
		'name'=>'bob',
		'children' => '[{"name":"sue"}]'
]];

#+ make the getter and setter methods {
$get_from_db = function($Record) use ($database_records){
	return $database_records[$Record->id];
};
$set_to_db = function($Record, $changes) use ($database_records){
	$record_data = $database_records[$Record->id];
	$record_data= \Grithin\Dictionary::diff_apply($record_data, $changes);
	$database_records[$Record->id] = $record_data;
	return $record_data;
};
#+ }

# initialize the record, using the 'id' option
$record = new \Grithin\Record(false, $get_from_db, $set_to_db, ['id'=>1]);
$record->get();

# let's capitalize the name when we set it
$capitalize_name = function($Record, $diff){
	if(isset($diff['name'])){
		$diff['name'] = strtoupper($diff['name']);
	}
};
$record->before_change($capitalize_name);

$record['name'] = 'bill';
# the name will become capitalized because of the event listener
$record['name']; #> BILL


# the children key points to a JSON string
$record['children']; #> '[{"name":"sue"}]'

# We can make it so JSON is automatically encoded and decoded when moving between the database
$jsonify = function($Record, $diff){
	if(isset($diff['children'])){
		$diff['children'] = json_encode($diff['children']);
	}
};
$unjsonify = function($Record){
	if(isset($Record['children'])){
		$Record->record['children'] = json_decode($Record->record['children'], true);
	}
};

$record->after_get($unjsonify);
$record->before_update($jsonify);

$record->get();

# now can access the full structure
$record['children'][0]['name']; #> sue
$record['children'][0]['name'] = 'jan';
$record->save(); # will encode the json for the db, then decode it for regular access

$record['children'][0]['name']; #> 'jan'

var_export($database_records);
/* >
array (
  1 =>
  array (
    'id' => 1,
    'name' => 'bob',
    'children' => '[{"name":"sue"}]',
  ),
)
*/

```

Some additional highlights from the above.

On the `after_get` function `unjsonify`, `$Record->record['children']` is used to avoid triggering more events.  If you don't mind `before_change` and `after_change` being run, `$Record['children']` could have been used.

When setting the record in the manner `$Record['name'] = 'bob'`, each assignment triggers two events.  It may be preferable to do a bulk change, which allows multiple things to change and will only emit two events.  This can be done with both the local copy and the source
```php
# update local copy
$Record->local_update(['name'=>'bill', 'age'=>1]);

# update both
$Record->update(['name'=>'bill', 'age'=>1]);
```







# Overview

# Events, Observers
Types:
-	EVENT_AFTER_GET : immediately after getter function is called.  This can be used to decode JSON
-	EVENT_CHANGE_BEFORE
-	EVENT_CHANGE_AFTER
-	EVENT_UPDATE_BEFORE : before updating the source.  This can be used to re-encode JSON
-	EVENT_UPDATE_AFTER

Convenience functions `after_get`, `before_change`, `after_change`, `before_update`, `after_update` will call the parameter function on the corresponding event with parameters `($this, $details)`, where in `$details` is an array object of the change.


![Events](about/events.png?raw=true "Events")

(EVENT_UPDATE_AFTER runs after EVENT_AFTER_GET since it may require the data to be reformatted by EVENT_AFTER_GET)


Update and Change events will fire only if there were changes.

The diff parameter presented to event observers is an ArrayObject, and that object is used when applying the diff to the record.  Consequently, mutating the diff within a "before" event observer will affect the resulting record.  If the diff count becomes 0, no change will be applied.

Potential uses of observers:
-	mutate a particular column (timestamp to datetime format)
-	check validity of record state.  Throw an exception if a bad state, or clear the diff
-	create a composite column.  Ex: total_cost = cost1 + cost2
-	logging specific changes

