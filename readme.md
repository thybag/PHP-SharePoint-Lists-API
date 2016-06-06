# PHP SharePoint Lists API

The **PHP SharePoint Lists API** is designed to make working with SharePoint Lists in PHP a less painful developer experience. Rather than messing around with SOAP and CAML requests, just include the SharePoint lists API in to your project and you should be good to go. This library is free for anyone to use and is licensed under the MIT license.

Using the PHP SharePoint Lists API, you can easily create, read, edit and delete from SharePoint list. The API also has support for querying list metadata and the list of lists.

Known to work with: SharePoint 2007 and SharePoint online (experimental).

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

###### To return all items from a list use either

    $sp->read('<list_name>'); 

or

    $sp->query('<list_name>')->get();


###### To return only the first 10 items from a list use:

    $sp->read('<list_name>', 10); 

or

    $sp->query('<list_name>')->limit(10)->get();


###### To return all the items from a list where surname is smith use:

    $sp->read('<list_name>', NULL, array('surname'=>'smith')); 

or

    $sp->query('<list_name>')->where('surname', '=', 'smith')->get();


###### To return the first 5 items where the surname is smith and the age is 40

    $sp->read('<list_name>', 5, array('surname'=>'smith','age'=>40)); 

or

    $sp->query('<list_name>')->where('surname', '=', 'smith')->and_where('age', '=', '40')->limit(5)->get();

###### To only execute code if the query successfully found results

If you have a query like this
    
    $result = $sp->read('<list_name>', 5, array('surname'=>'smith','age'=>40)); 

You can write code to only execute if the query executes without warnings (it will give a warning if the query gets 0 results)

    if(isset($result['warning'])) {
        //Code to execute on warnings
    }
    else {
        //Code to execute on successful queries
    }

###### To return the first 10 items where the surname is "smith" using a particular view, call: (It appears views can only be referenced by their GUID)

    $sp->read('<list_name>', 10, array('surname'=>'smith','age'=>40),'{0FAKE-GUID001-1001001-10001}'); 

or

     $sp->query('<list_name>')->where('surname', '=', 'smith')->and_where('age', '=', '40')->limit(10)->using('{0FAKE-GUID001-1001001-10001}')->get();


###### To return the first 10 items where the surname is smith, ordered by age use:

    $sp->read('<list_name>', 10, array('surname'=>'smith'), NULL, array('age' => 'desc')); 

or

    $sp->query('<list_name>')->where('surname', '=', 'smith')->limit(10)->sort('age','DESC')->get();


###### To return the first 5 items, including the columns "favroite_cake" and "favorite animal"

    $sp->read('<list_name>', 5, NULL, array("favroite_cake", "favorite_animal")); 

or

    $sp->query('<list_name>')->fields(array("favroite_cake", "favorite_animal")->limit(5)->get();


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

If you need to return 5 items, but including all fields contained in a list, you can use. (pass false to all_fields to include hidden fields).

    $sp->query('list of pets')->all_fields()->get();

If you have a set of CAML for a specific advanced query you would like to run, you can pass it to the query object using:

    $sp->query('list of pets')->raw_where('<Eq><FieldRef Name="Title" /><Value Type="Text">Hello World</Value></Eq>')->limit(10)->get();


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

#### List MetaData.
You can access a lists meta data (Column configuration for example) by calling

    $sp->readListMeta('My List');

If you are having trouble with the column name (for example, getting the error "One or more field types are not installed properly") then the metadata may help. Use the following line of code to get the metadata for your list, and use the column's "staticname"

    print_r($sp->readListMeta('My List')); //Prints all metadata for 'My List'

Alternatively, this line will show you just the column names for your list:

    foreach($sp->readListMeta('My List') as $i){echo$i['staticname']."<br>";} //Prints just column names for 'My List'


By default the method will attempt to strip out non-useful columns from the results, but keep "hidden". If you'd like the full results to be returned call:

    $sp->readListMeta('My List',FALSE);

You can also now ignore "hidden" columns:

    $sp->readListMeta('My List', FALSE, TRUE);

#### Field history / versions.
If your list is versioned in SharePoint, you can read the versions for a specific field using:

    $sp->getVersions('<list>', '<id>', '<field_name>');

#### Attach a file to a SharePoint list item
Files can be attached to SharePoint list items using:

    $sp->addAttachment('<list>', '<id>', '<path_to_file>');

#### Get the URL of a file attached to a SharePoint list item
To read an attachment, you need the list name and the item id.

    $sp->getAttachments('<list>', '<id>')

This will return an array, with each item being a path to a file.

### Download file attached to a SharePoint list 

There is no built in way to download an attachment. If we can count on our user to be logged into a SharePoint account with access to the needed file, you can create a link to the attachment's URL using the above method. Otherwise, we can use CURL to download the attachment to our server.

Before we can use CURL, we will need an authentication cookie. The easiest way to get this is with the cookies.txt extension for Chrome. Log in to SharePoint with the account you want to download the attachments with, and copy the cookie data from cookies.txt into a new file. **WARNING: This cookie will enable people to log into your SharePoint account, and should be kept secure.**

Now that you have the cookie saved on your server, you can download attachments with the following code:

    $ch = curl_init();
    $url = "URL RETURNED BY getAttachments()";
    curl_setopt($ch, CURLOPT_URL,$url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt( $ch, CURLOPT_COOKIEFILE,"PATH TO YOUR COOKIE FILE");
    $attachmentData = curl_exec($ch);
    file_put_contents("PATH WHERE YOU WANT TO SAVE THE IMAGE", $attachmentData);

### Helper methods

The PHP SharePoint API contains a number of helper methods to make it easier to ensure certain values are in the correct format for some of SharePoints special data types.

###### dateTime

The dataTime method can either be passed a text based date

     $date = \Thybag\SharepointApi::dateTime("2012-12-21");

Or a unix timestamp

    $date = \Thybag\SharepointApi::dateTime(time(), true);

And will return a value which can be stored in to SharePoints DateTime fields without issue.

###### Lookup

The lookup data type in SharePoint is for fields that reference a row in another list. In order to correctly populate these values you will need to know the ID of the row the value needs to reference.

    $value = \Thybag\SharepointApi::lookup('3','Pepperoni Pizza');

If you do not know the name/title of the value you are storing the method will work fine with just an ID (which SharePoint will also accept directly)
    
    $value = \Thybag\SharepointApi::lookup('3');

###### Magic Lookup

If you are attempting to store a value in a "lookup" data type but for some reason only know the title/name of the item, not its ID, you can use the MagicLookup method to quickly look this value up and return it for you. This method will need to be passed both the items title & the list it is contained within.

    $sp->magicLookup("Pepperoni Pizza", "Pizza List");

## Troubleshooting

* Unable to find the wrapper "https"

If you are getting this error it normally means that php_openssl (needed to curl https urls) is not enabled on your webserver. With many local websevers (such as XAMPP) you can simply open your php.ini file and uncomment the php_openssl line (ie. remove the ; before it).

* One or more field types are not installed properly

This error can happen when you try to reference a column name that does not exist (for example, using the human-readable name rather than the internal name). Refer to the List MetaData section of this document for more help.

* Not all fields are returned by query

By default, sharepoint only returns fields that are visible in the default view. To get around this, use:

    $sp->query('some list')->all_fields()->get();

You can look at [issue #59](https://github.com/thybag/PHP-SharePoint-Lists-API/issues/59) for more detail.

* Looks like we got no XML document

This error should be fixed in the latest version.

If you are getting this error while trying to connect to SharePoint Online, it is due to a recent change in the way Microsoft handles authentication cookies.  Open the file SharePointOnlineAuth.php and either comment out or remove the following line:

    unset($authCookies[0]); // No need for first cookie

You can look at [issue #83](https://github.com/thybag/PHP-SharePoint-Lists-API/issues/83) for more detail on this problem.
