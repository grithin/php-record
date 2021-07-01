<?php
/** An arbitrarily deep array that tracks changes, emits events,


To manage tracking the difference of sub-arrays, the sub-arrays are turned into SubRecordHolders.

@refdoc {Diff As ArrayObject} Diff uses ArrayObject so that changes made to the diff from listening events are passed over to the applicate of the diff (b/c it is an object (passed by reference))
@refstruct {observer} < function(\Grithin\Record $Record, ArrayObject $diff): void {} >

*/

namespace Grithin;

use \Grithin\Arrays;
use \Grithin\Tool;
use \Grithin\SubRecordHolder;

use \Exception;
use \ArrayObject;



class Record implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable {
	public $stored_record; # the last known record state from the getter
	public $record; # the current record state, with potential changes

	const EVENT_AFTER_GET = 1;
	const EVENT_CHANGE_BEFORE = 2;
	const EVENT_CHANGE_AFTER = 4;
	const EVENT_UPDATE_BEFORE = 8;
	const EVENT_UPDATE_AFTER = 16;


	/** By default, the diff function will turn objects into arrays.  This is not desired for something like a Time object, so, instead, use a equals comparison comparer */
	public $diff_options = ['object_comparer'=>[\Grithin\Dictionary::class, 'diff_comparer_equals']];

	public $getter;
	public $setter;


	/** params
	< data > < t:array > < the initial record data > < if false, use getter to get initial data >
	< getter > < function(this) >
	< setter > < the function that sets the underlying record.  This could be the function that updates the database value > < function(changes, this): record {} >
	< options >
		id: < an identifying value for getter and setter to use >
		query: < an identifying query for getter and setter to use >
	*/
	public function __construct($data=[], $getter=null, $setter=null, $options=[]) {
		$this->observers = new \SplObjectStorage();


		# set properties from options
		$properties = Arrays::pick($options, ['id', 'query']);
		foreach($properties as $k=>$property){
			$this->$k = $property;
		}

		if(!$getter){
			$getter = [$this, 'default_getter'];
		}
		$this->getter = $getter;
		if(!$setter){
			$setter = [$this, 'default_setter'];
		}
		$this->setter = $setter;

		$this->options = array_merge($options, ['getter'=>$getter, 'setter'=>$setter]);
		if($data === false){
			$data = $this->options['getter']($this);
		}

		$this->stored_record = $this->record = Arrays::from($data);

		if(!is_array($this->record)){
			throw new Exception('record must be an array');
		}
	}
	/** Just returns the current record data */
	public function default_getter($Record){
		return $this->record;
	}
	/** updates the record data
	@return	array the new, full record
	*/
	/** params
	< Record > < the Record instance >
	< change > < t:\ArrayObject > < the change to the record >
	*/
	public function default_setter($Record, $change){
		$this->record = array_merge($this->record, Arrays::from($change));
		return $this->record;
	}

	/** pull the record from the source using the getter, update local record, and emit event */
	public function get(){
		$this->record_previous = $this->record;
		$this->record = $this->stored_record = ($this->getter)($this);

		if(!is_array($this->record)){
			throw new Exception('record must be an array');
		}

		$this->notify(self::EVENT_AFTER_GET, $this->calculate_changes($this->record_previous));
		return $this->record;
	}

	/** for \Countable */
	public function count(){
		return count($this->record);
	}
	/** for \IteratorAggregate */
	public function getIterator() {
		return new \ArrayIterator($this->record);
	}

	/** update stored and local without notifying listeners */
	public function deaf_set($changes){
		$this->record = Arrays::replace($this->record, $changes);
		$this->stored_record = Arrays::replace($this->stored_record, $changes);
	}
	/** for ArrayAccess */
	public function offsetSet($offset, $value) {
		$this->local_update([$offset=>$value]);
	}
	/** for ArrayAccess */
	public function offsetExists($offset) {
		return isset($this->record[$offset]);
	}
	/** for ArrayAccess */
	public function offsetUnset($offset) {
		$this->local_update([$offset=>(new \Grithin\MissingValue)]);
	}
	/** for ArrayAccess */
	public function offsetGet($offset) {
		if(is_array($this->record[$offset])){
			return new SubRecordHolder($this, $offset, $this->record[$offset]);
		}

		return $this->record[$offset];
	}
	/** for JsonSerializable */
	public function jsonSerialize(){
		return $this->record;
	}
	/** hopefully PHP adds this at some point */
	public function __toArray(){
		return $this->record;
	}

	/** a SplObjectStorage so that observers can be removed using function references */
	public $observers;

	/** attach an observer for all events */
	public function attach($observer) {
		$this->observers->attach($observer);
	}
	/** detach an observer
	@param	closure	$observer	the observer that was attached
	*/
	public function detach($observer) {
		$this->observers->detach($observer);
	}
	/** return an callback for use as an observer than only responds to particular events
	@return	closure
	 */
	static function event_callback_wrap($event, $observer){
		return function($that, $type, $details) use ($event, $observer){
			if(is_int($type) && #< ensure this is a numbered event
				$type & $event) #< filter fired event type against the type of listener
			{
				return $observer($that, $details);
			}
		};
	}

	/** after the getter returns the data */
	/**
	@param	object	$observer	function(\Grithin\Record $Record)
	@return	closure	the observer that was attached
	*/
	public function after_get($observer){
		$wrapped = $this->event_callback_wrap(self::EVENT_AFTER_GET, $observer);
		$this->attach($wrapped);
		return $wrapped;
	}

	/** before a local change is made, call function */
	/**
	@param	object	$observer	see @{observer}
	@return	closure	the observer that was attached
	*/
	public function before_change($observer){
		$wrapped = $this->event_callback_wrap(self::EVENT_CHANGE_BEFORE, $observer);
		$this->attach($wrapped);
		return $wrapped;
	}
	/** after a local change is made, call function */
	/**
	@param	object	$observer	see @{observer}
	@return	closure	the observer that was attached
	*/
	public function after_change($observer){
		$wrapped = $this->event_callback_wrap(self::EVENT_CHANGE_AFTER, $observer);
		$this->attach($wrapped);
		return $wrapped;
	}
	/** before a source data update is made, call function */
	/**
	@param	object	$observer	see @{observer}
	@return	closure	the observer that was attached
	*/
	public function before_update($observer){
		$wrapped = $this->event_callback_wrap(self::EVENT_UPDATE_BEFORE, $observer);
		$this->attach($wrapped);
		return $wrapped;
	}
	/** after a source data update is made, call function */
	/**
	@param	object	$observer	see @{observer}
	@return	closure	the observer that was attached
	*/
	public function after_update($observer){
		$wrapped = $this->event_callback_wrap(self::EVENT_UPDATE_AFTER, $observer);
		$this->attach($wrapped);
		return $wrapped;
	}


	public function notify($type, $details=[]) {
		foreach ($this->observers as $observer) {
			$observer($this, $type, $details);
		}
	}


	/** does not apply changes, just calculates potential */
	public function calculate_changes($target){
		return Dictionary::diff($target, $this->record, $this->diff_options);
	}

	/** see the changes that have been made on the local record compared to the source record */
	public function changes(){
		return Dictionary::diff($this->record, $this->stored_record, $this->diff_options);
	}
	/** see the changes that have been made when comparing the previous record state */
	/** Exampple
	# can be used to see if anything changed in the Db
	$record->get();
	$record->previous_changes();
	*/
	public function previous_changes(){
		return Dictionary::diff($this->record, $this->previous_record, $this->diff_options);
	}

	public $stored_record_previous;


	/** alias for .apply */
	public function save(){ $this->apply(); }
	/** Apply the changes made to the record: call the setter, emit events, and update .stored_record

	@return	\ArrayObject changes
	*/

	public function apply(){
		$this->stored_record_previous = $this->stored_record;
		$diff = new ArrayObject(Dictionary::diff($this->record, $this->stored_record, $this->diff_options)); # see @{Diff As ArrayObject}
		if(count($diff)){
			$this->notify(self::EVENT_UPDATE_BEFORE, $diff);
			if(count($diff)){ # may have been mutated to nothing
				$this->stored_record = $this->record = $this->options['setter']($this, $diff);
				$this->notify(self::EVENT_AFTER_GET, $diff);
				$this->notify(self::EVENT_UPDATE_AFTER, $diff);
			}
		}
		return $diff;
	}
	/** unapply the last application of changes
	@return	\ArrayObject changes
	*/
	public function apply_reverse(){
		$this->record = $this->stored_record_previous;
		return $this->apply();
	}

	/** apply partial changes to the local copy of record, and emit events */
	public function local_update($changes){
		$new_record = Arrays::replace($this->record, $changes);
		return $this->local_replace($new_record);
	}

	public $record_previous; # the $this->record prior to changes; potentially used by event handlers interested in the previous unsaved changes

	/** replace the local record with some new record, and emit events */
	public function local_replace($new_record){
		$this->record_previous = $this->record;
		$diff = new ArrayObject(Dictionary::diff($new_record, $this->record, $this->diff_options)); # see @{Diff As ArrayObject}
		if(count($diff)){
			$this->notify(self::EVENT_CHANGE_BEFORE, $diff);
			if(count($diff)){ # may have been mutated after event to nothing
				$this->record = Dictionary::diff_apply($this->record, $diff);
				$this->notify(self::EVENT_CHANGE_AFTER, $diff);
			}
		}
	}
	/** reverse the last replacement of the local record */
	public function local_replace_reverse(){
		$this->local_replace($this->record_previous);
	}


	/** replace update the record to be the new */
	public function replace($new_record){
		$this->local_replace($new_record);
		$changes = $this->apply();
		return $changes;
	}
	/** update the record (local and source) with multiple changes */
	public function update($changes){
		$this->local_update($changes);
		$changes = $this->apply();
		return $changes;
	}

}
