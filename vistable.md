# Introduction #

vistable is the base class implementing a datasource. It uses [visparser](visparser.md) to parse the query language, and [visformat](visformat.md) to output formatted dates, times, numbers and booleans.


# Details #

The vistable constructor takes 6 parameters: $tqx, $tq, $tqrt, $tz, $locale and $extra.

The $tqx, $tq, and $tqrt params correspond to the query parameters of the same names described in the [google documentation](http://code.google.com/apis/visualization/documentation/dev/implementing_data_source.html).

The $tz and $locale parameters are used by the formatters (all dates are internally handled UTC - the time zone is used to display them in an appropriate local time).

The $extra parameter can be used to pass in default values for any custom tqx parameters. It can also be used to turn on debug output ($extra = array('debug' => TRUE)).

vistable is an abstract base class. There are two derived classes, [mysql\_vistable](mysql_vistable.md), and [csv\_vistable](csv_vistable.md).