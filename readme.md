# PHP SharePoint Lists API

The **PHP SharePoint Lists API** is designed to make working with SharePoint Lists in PHP a less painful developer experience. Rather than messing around with SOAP and CAML requests, just include the SharePoint lists API in to your project and you should be good to go. This library is free for anyone to use and is licensed under the MIT license.

Using the PHP SharePoint Lists API, you can easily create, read, edit and delete from SharePoint list. The API also has support for querying list metadata and the list of lists.

Known to work with: SharePoint 2007, SharePoint Online.

### Usage Instructions

#### Installation

Download the WSDL file for the SharePoint Lists you want to interact with. This can normally be obtained at:
    `sharepoint.url/subsite/_vti_bin/Lists.asmx?WSDL`

If you are using [composer](http://getcomposer.org/), just add [thybag/php-sharepoint-lists-api](https://packagist.org/packages/thybag/php-sharepoint-lists-api) to your `composer.json` and run composer.

    {
        "require": {
            "thybag/php-sharepoint-lists-api": "dev-master"
        }
    }

If you are not using composer you can download a copy of the SharePointAPI files manually and include the top "SharePointAPI.php" class in your project.

#### Creating SharePointAPI object

In order to use the PHP SharePoint Lists API you will need a valid user/service account with the permissions to the required list. 

For most SharePoint installations, you can create a new instance of the API using:

    use Thybag\SharePointAPI;
    $sp = new SharePointAPI('<username>', '<password>', '<path_to_WSDL>');

If your installation requires NTLM Authentication, you can instead use:

    use Thybag\SharePointAPI;
    $sp = new SharePointAPI('<username>', '<password>', '<path_to_WSDL>', 'NTLM');

SharePoint Online users must use:

    use Thybag\SharePointAPI;
    $sp = new SharePointAPI('<username>', '<password>', '<path_to_WSDL>', 'SPONLINE');


All methods return an Array by default. `SetReturnType` can be used to specify that results should be returned as objects instead.

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
The query method can be used when you need to specify a query that is to complex to be easily defined using the read methods. Queries are constructed using a number of (hopefully expressive) pseudo SQL methods.

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

In order to delete a row, an ID as well as list name is required. To remove the record for James with the ID 5 you would use:

    $sp->delete('<list_name>', '5');

If you wished to delete a number of records at once, an array of ID's can also be passed to the delete multiple method

    $sp->deleteMultiple('<list_name>', array('6','7','8'));

#### CRUD - Create, Read, Update and Delete
The above actions can also be performed using the CRUD wrapper on a list. This may be useful when you
want to perform multiple actions on the same list. Crud methods do not require a list name to be passed in.

    $list = $sp->CRUD('<list_name>');
    $list->read(10);
    $list->create(array( 'id'=>1, 'name'=>'Fred' ));

#### List all Lists.
You can get a full listing of all available lists within the connected SharePoint subsite by calling:

    $sp->getLists();

#### List metaData.
You can access a lists meta data (Column configuration for example) by calling

    $sp->readListMeta('My List');

By default the method will attempt to strip out non-useful columns from the results, but keep "hidden". If you'd like the full results to be returned call:

    $sp->readListMeta('My List',FALSE);

You can also now ignore "hidden" columns:

    $sp->readListMeta('My List', FALSE, TRUE);


### Helper methods

The PHP SharePoint API contains a number of helper methods to make it easier to ensure certain values are in the correct format for some of SharePoints special data types.

#### dateTime

The dataTime method can either be passed a text based date

     $date = \Thybag\SharepointApi::dateTime("2012-12-21");

Or a unix timestamp

    $date = \Thybag\SharepointApi::dateTime(time(), true);

And will return a value which can be stored in to SharePoints DateTime fields without issue.

#### Lookup

The lookup data type in SharePoint is for fields that reference a row in another list. In order to correctly populate these values you will need to know the ID of the row the value needs to reference.

    $value = \Thybag\SharepointApi::lookup('3','Pepperoni Pizza');

If you do not know the name/title of the value you are storing the method will work fine with just an ID (which SharePoint will also accept directly)
    
    $value = \Thybag\SharepointApi::lookup('3');

#### Magic Lookup

If you are attempting to store a value in a "lookup" data type but for some reason only know the title/name of the item, not its ID, you can use the MagicLookup method to quickly look this value up and return it for you. This method will need to be passed both the items title & the list it is contained within.

    $sp->magicLookup("Pepperoni Pizza", "Pizza List");

## Trouble shooting

* Unable to find the wrapper "https"

If you are getting this error it normally means that php_openssl (needed to curl https urls) is not enabled on your webserver. With many local websevers (such as XAMPP) you can simply open your php.ini file and uncomment the php_openssl line (ie. remove the ; before it).

