# PHP SharePoint Lists API#

The *PHP SharePoint Lists API* is designed to make working with SharePoint Lists easier and less error prone. With it, you no longer need to worry about SOAP and can just get with doing what you actually need to do. This library is free for anyone to use and is licensed under the MIT license.

The current version includes the ability to read, query, edit, delete and add to existing SharePoint lists, plus the ability to query the ListOfLists and to return a lists metadata.

All methods will return Array as results by default. SetReturnType can be used to specify that results should be returned as objects.

Tested on SharePoint 2007.

### Usage Instructions

Download the WSDL file for the Lists that you want to interact with, this can normally be obtained at:
    sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL

Include the SharePointAPI in to your project and create a new instance of it.
The script requires a user account with access to the list in order to function.

    $sp = new SharePointAPI('<username>', '<password>', '<path_to_WSDL>');

#### Reading from a List.

To return all items from a list use:

    $sp->read('<list_name>'); 

To return only the first 10 items from a list use:

    $sp->read('<list_name>', 10); 

To return all the items from a list where surname is smith use:

    $sp->read('<list_name>', NULL, array('surname'=>'smith')); 

To return the first 5 items where the surname is smith and the age is 40

    $sp->read('<list_name>', 5, array('surname'=>'smith','age'=>40)); 

To return the first 10 items where the surname is "smith" using a particular view, call: (It appears views can only be referenced by their GUID)

    $sp->read('<list_name>', 10, array('surname'=>'smith','age'=>40),'{0FAKE-GUID001-1001001-10001}'); 

To return the first 10 items where the surname is smith, ordered by age use:

    $sp->read('<list_name>', 10, array('surname'=>'smith'), NULL, array('age' => 'desc')); 

By default list item's are returned as arrays with lower case index's. If you would prefer the results to return as object's, before invoking any read operations use:

    $sp->setReturnType('object'); 

Automatically making the attribute names lowercase can also be deactivated by using:

    $sp->lowercaseIndexs(FALSE);

#### Querying a list
The query method can be used when you need to specify a query that is to complex to be easily defined using the read methods. Queries are constructed using a number of (hopefully expressive) Pseudo SQL methods.

If you for example wanted to query a list of pets and return all dogs below the age of 5 (sorted by age) you could use.

    $sp->query('list of pets')->where('type','=','dog')->and_where('age','<','5')->sort('age','ASC')->get();

If you wanted to get the first 10 pets that were either cats or hamsters you could use:

    $sp->query('list of pets')->where('type','=','cat')->or_where('type','=','hamster')->limit(10)->get();

#### Adding to a list

To add a new item to a list you can use either the method "write", "add" or "insert" (all function identically). Creating a new record in a List with the columns forename, surname, age and phone may look like:

    $sp->write('<list_name>', array('forename'=>'Bob','surname' =>'Smith', 'age'=>40, 'phone'=>'(00000) 000000' ));

You can also run multiple write operations together by using:

 	$sp->writeMultiple('<list_name>', array(
		array('forename' => 'James'),
		array('forename' => 'Steve')
	));

#### Editing Rows

To edit a row you need to have its ID. Assuming the above row had the ID 5, we could change Bob's name to James with:

    $sp->update('<list_name>','5', array('forename'=>'James'));

As with the write method you can also run multiple update operations together by using:

 	$sp->updateMultiple('<list_name>', array(
		array('ID'=>5,'job'=>'Intern'),
		array('ID'=>6,'job'=>'Intern')
	));

When using updateMultiple every item MUST have an ID.

#### Deleting Rows

To remove rows an ID is also required, to remove the record for James with the ID 5 you would use:

    $sp->delete('<list_name>', '5');

#### CRUD - Create, Read, Update and Delete
The above actions can also be performed using the CRUD wrapper on a list. This may be useful when you
want to perform multiple actions on the same list. Crud methods do not require a list name to be passed in.

    $list = $sp->CRUD('<list_name>');
    $list->read(10);
    $list->create(array( 'id'=>1, 'name'=>'Fred' ));

#### List all Lists.
You can get a full listing of all avaiable lists within the connected sharepoint subsite by calling:

    $sp->getLists();

#### List metaData.
You can access a lists meta data (Column configurtion for example) by calling

    $sp->readListMeta('My List');

By default the method will attempt to strip out non-useful columns from the results, but keep "hidden". If you'd like the full results to be returned call:

    $sp->readListMeta('My List',FALSE);

You can also now ignore "hidden" colums:

    $sp->readListMeta('My List',FALSE, TRUE);
