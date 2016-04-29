Vagrant.configure("2") do |config|
	config.vm.box = "ubuntu/trusty64"
	config.vm.box_check_update = false
  config.vm.provider :virtualbox do |vb|
      vb.customize ['modifyvm', :id,'--memory', '2048']
  end
	config.vm.provision :shell, path: "project_resources/vagrant/provision.sh"
	#config.vm.network "public_network", ip: "10.11.12.40"
	config.vm.network "public_network"
  config.vm.network :forwarded_port, guest: 9200, host: 9200
  config.vm.network :forwarded_port, guest: 80, host: 8989
	#config.vm.network :forwarded_port, guest: 80, host: 8888
	#config.vm.network :forwarded_port, guest: 9200, host: 80
	#config.vm.network :forwarded_port, guest: 9200, host: 8888
	config.vm.network :forwarded_port, guest: 5601, host: 5601
	config.vm.network :forwarded_port, guest: 9200, host: 9300

  config.vm.synced_folder ".", "/var/www", owner: "www-data", group: "www-data"
  config.vm.hostname = "dev.elasticsearch.com"
end
