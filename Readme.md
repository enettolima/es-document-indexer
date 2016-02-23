
# ElasticSearch 2.2 with Vagrant
This vagrant box will install elasticsearch 2.2, marvel 2.0 and kibana 4.4.1 on Ubuntu 14.04

## Prerequisites
[VirtualBox](https://www.virtualbox.org/) and [Vagrant](https://www.vagrantup.com/) (minimum version 1.6)

### Install and run
After cloning this repository run
```bash
vagrant up
```

#### Configure your box network
Once the installation is done, access your box via ssh

	vagrant ssh

Your box is set to get an ip address automatically from your local network, so you may need to edit your elasticsearch and kibana configurations:


#### Elasticsearch Configuration
Run a command to get the ip address from your box:

	ifconfig

You should see:

	eth1      Link encap:Ethernet  HWaddr 08:00:27:e1:81:c0  
          	  inet addr:192.168.1.100  Bcast:192.168.3.255  Mask:255.255.255.0

Edit the ElasticSearch Config file:

	vim /etc/elasticsearch/elasticsearch.yml

Change the network.host line to:

	network.host: 192.168.1.100


#### Kibana Configuration

	vim /etc/elasticsearch/kibana/config/kibana.yml

Make sure the begining of your file looks like this:

	#Kibana is served by a back end server. This controls which port to use.
	#server.port: 5601

	#The host to bind the server to.
	#server.host: "0.0.0.0"

	#If you are running kibana behind a proxy, and want to mount it at a path,
	#specify that path here. The basePath can't end in a slash.
	#server.basePath: ""

	#The maximum payload size in bytes on incoming server requests.
	#server.maxPayloadBytes: 1048576

	#The Elasticsearch instance to use for all your queries.
	elasticsearch.url: "http://192.168.1.100:9200"

With this done, just restart Elastic:

    sudo service elasticsearch restart

And start kibana:

    cd /etc/elasticsearch/kibana/
    ./bin/kibana

To access ElasticSearch try on your browser:

	http://192.168.1.100:9200/_cluster/health?pretty

To access kibana go to:

    http://localhost:5601/app/kibana


#### ElasticSearch Configuration Folders

* Log directory -> /var/log/elasticsearch
* Data directory -> /var/lib/elasticsearch
* Configuration directory -> /etc/elasticsearch
* Binary directory -> /usr/share/elasticsearch
