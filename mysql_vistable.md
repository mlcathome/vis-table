# Introduction #

mysql\_vistable allows you to present the data from a mysql database as a google visualization datasource.


# Details #

Create a table with: `$vt = new mysql_vistable($tqx, $tq, $tqrt,$tz,$locale,$extra);`

The parameters are as describe in [vistable](vistable.md)

Next setup the connection to your database (ie mysql\_connect() and mysql\_select\_db()).

Then tell $vt how to get the data:

`$vt->setup_database($tables, $fields);`

$tables is a string which will be passed as the "FROM" parameter of a SELECT query.
$fields is an array of fields fetch. Its an associative array. The keys are the names that the vistable will present. The values can be null (in which case, the same name will be used in the mysql SELECT statement), or it can be a string (in which case that string will be used as the mysql field name).

So, eg
```
$vt->setup_database('workouts LEFT JOIN users on workouts.luid=users.luid',
                    array('user_name' => 'users.name', 
                          'workout_name' => 'workouts.name', 
                          'date' => null,
                          'distance' => null,
                          'time' => null, 
                          'location' => null));
```

In this case, the basic query that will be presented to mysql will be:

```
SELECT users.name AS user_name, workouts.name AS workout_name, date, distance, time, location FROM workouts LEFT JOIN users on workouts.luid=users.luid
```

But note that when you use the google visualization query language, the query will normally be translated into an equivalent mysql query for efficiency (there is an option not to do that, and have [vistable](vistable.md) do all the query language processing - but that will typically be less efficient).