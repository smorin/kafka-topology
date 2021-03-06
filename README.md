kafka-topology
==============

A simple web application that scans zookeeper and kafka for topics and which consumer groups consume them. 
It is built on kafka-php library and also uses heavlity php-zookeeper extension.
It could also be an interface to create and delete topics.

Quick Start
===========
1. assuming http server + php is running on localhost:80 with document root in /var/www 
2. you will need extnesions: php5-curl and php-zookeeper (https://github.com/andreiz/php-zookeeper)
3. git clone --recursive git://github.com/michal-harish/kafka-topology.git into /var/www/kafka-topology
4. http://localhost/kafka-topology/public/?Local=localhost:2181

NOTE: You can monitor mutliple clusters by adding &<name>=<zk-connect-string> at the end of the url

NOTE: Monitors are actually infinite ajax requests and the portlets have zookeeper watchers so
should update without refreshing the page if there is a rebalance.  

BackLog
=======
- implement live scan of consumer-topic-partition ownership (ephemeral zk nodes /consumers/owners)
- store the watch status in file for reliable change detection 
