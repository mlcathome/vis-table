# Introduction #

csv\_vistable is a simple extension of vistable that reads a csv file and serves it as a google datasource


# Details #

See test/csvtable.php for usage (or try it out at http://maps.myosotissp.com/vistable/test/csvtable.php)

The first row of the csv file is taken as the column ids.

If an id ends with "as `<type>`" where `<type>` is one of date,datetime,timeofday,number,boolean, "as `<type>`" will be stripped, and the type of the column will be set to `<type>`. Otherwise the type of the column will be set to string.