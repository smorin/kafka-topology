<?php
	require __DIR__ . '/../kafka-php/src/Kafka/Kafka.php';
	
	$connectionString = urldecode($_SERVER['QUERY_STRING']);
	$zk = new ZooKeeper($connectionString);
	
	if ($consumerIds = @$zk->getChildren('/consumers')) {
		$consumers =  array_fill_keys($consumerIds, array());
	} else $consumers = array();
	
	if (isset($_SERVER['HTTP_PORTLET_AJAX'])) {
		class watcher {
			private $changedPath = null;
			public function __construct($zk, array $paths, $min_sleep_sec = 1) {			
				foreach($paths as $path) $zk->getChildren($path, array($this, 'watch'));
				while ($this->changedPath === null) sleep($min_sleep_sec);
			}
			public function watch($event, $key, $path) {
				$this->changedPath = $path;
			}
		}
		$watchPaths = array('/brokers/ids', '/consumers');
		foreach(array_keys($consumers) as $consumer) {
			$watchPaths[] = "/consumers/{$consumer}/ids";
		}
		new watcher($zk, $watchPaths);
	}
	 
	header("Cache-Control: max-age=0, no-store");
	
	$brokers = array();
	if ($brokerIds = @$zk->getChildren('/brokers/ids'))
	{		
		foreach($brokerIds as $brokerId)
		{
			$brokerHash = @$zk->get("/brokers/ids/{$brokerId}");
			$hostPort = explode(":",$brokerHash);
			$brokers[$brokerId] = "{$hostPort[1]}:{$hostPort[2]}";
			$kafka = new \Kafka\Kafka($hostPort[1], $hostPort[2], 30);
			$kafkaConsumers[$brokerId] = $kafka->createConsumer(); 
		}
	}
	$topics = array();
	if ($topicNames = @$zk->getChildren('/brokers/topics'))
	{
		foreach($topicNames as $topicName)
		{
			foreach($brokers as $brokerId => $brokerName)
			{		
				$brokerPartitionCount = @$zk->get("/brokers/topics/{$topicName}/{$brokerId}");
				for($partition=0; $partition<$brokerPartitionCount; $partition++)
				{
					$partitionId = "{$brokerId}-{$partition}";
					$smallest = array_shift($kafkaConsumers[$brokerId]->offsets($topicName,$partition, \Kafka\Kafka::OFFSETS_EARLIEST));
					$largest = array_shift($kafkaConsumers[$brokerId]->offsets($topicName,$partition, \Kafka\Kafka::OFFSETS_LATEST));
					$partitionStatus = array(
						'id' => $partitionId,
						'smallest' => $smallest->__toString(),
					    'largest' => $largest->__toString(),
					);
					$topics[$topicName][$partitionId] = $partitionStatus;
					$sections[$topicName][$brokerId][$partition] = $partitionStatus;
				}
			}
		}
	}
	
	foreach(array_keys($consumers) as $consumerId)
	{
		$consumerInfo = array(
		    'active' => false,
		    'abandoned' => true,
		);
		foreach(@$zk->getChildren("/consumers/{$consumerId}/ids") as $process) {
		    $consumerInfo['process'][$process] = array();
		}

		foreach(@$zk->getChildren("/consumers/{$consumerId}/offsets") as $topicName) {
			$consumerTopic = array(
			    'active' => false,
			    'abandoned' => true,
			    'lagging' => false,
			);
			foreach(@$zk->getChildren("/consumers/{$consumerId}/offsets/{$topicName}") as $partitionId) {
				$consumerPartitionStatus['watermark'] = @$zk->get("/consumers/{$consumerId}/offsets/{$topicName}/$partitionId");
				$smallest = $topics[$topicName][$partitionId]['smallest'];
				$largest = $topics[$topicName][$partitionId]['largest'];
				if ($consumerPartitionStatus['watermark'] < $smallest) {
				    $consumerPartitionStatus['progress'] = false;
				} elseif (is_numeric($smallest) && is_numeric($largest)) {
    				$consumerPartitionStatus['progress'] = round(
    				    100 * (intval($consumerPartitionStatus['watermark']) - intval($smallest))
    				    / (intval($largest) - intval($smallest))
    				    ,2
				    );
    				$consumerTopic['lagging'] = $consumerTopic['lagging'] || ($consumerPartitionStatus['progress'] < 90);
				}
				$consumerTopic['partition'][$partitionId] = $consumerPartitionStatus;
				$consumerTopic['abandoned'] = $consumerTopic['abandoned'] && $consumerPartitionStatus['progress'] === false;
			}
			$consumerTopicOwners = @$zk->getChildren("/consumers/{$consumerId}/owners/{$topicName}");
			if ($consumerTopicOwners) {
				foreach($consumerTopicOwners as $consumerProcess) {
					$consumerTopic['process'][$consumerProcess] = array();
				}
			}
			$consumerTopic['active'] = count($consumerTopic['process']) > 0;
			$consumerInfo['topics'][$topicName] = $consumerTopic;
			$consumerInfo['abandoned'] = $consumerInfo['abandoned'] && $consumerTopic['abandoned'];
		}
		$consumerInfo['active'] = count($consumerInfo['process']) > 0;
		$consumers[$consumerId] = $consumerInfo;
	}

	unset($zk);
	
?>
<body>
	<div id='topicsTable'>	
		<?php if ($brokers !== null) :?>
		<table>
			<thead>
				<tr>
					<th><?php echo $connectionString;?></th>
				<?php foreach($topics as $topicName => $partitions) : ?>
					<th><?php echo "$topicName(".count($partitions).")"; ?></th>
				<?php endforeach;?>
				</tr>
			</thead>
			<tbody>
				<?php foreach($brokers as $brokerId => $brokerName) : ?>
					<tr>
						<th><?php echo "[{$brokerId}] ";?><small><?php echo $brokerName;?></small></th>
						<?php foreach(array_keys($topics) as $topicName): ?>
						<td>
							<?php $p=0;foreach($sections[$topicName][$brokerId] as $partition => $status) : ?>
							<span class="alterColor<?php echo ++$p;?>"><?php echo " [<b>{$status['id']}</b>] ";?>
							<small><?php echo trim($status['smallest'],'0') .'-';?></small>
							<small><?php echo trim($status['largest'],0);?></small>
							</span><br/>
							<?php endforeach;?>
						</td>
						<?php endforeach; ?>
					</tr>		
				<?php endforeach;?>
			</tbody>
		</table>
		<?php endif;?>
	</div>

	<table id='consumerList'>	
	    <tr>
        <?php if ($consumers !== null) :?>
	    <td>
	       <h3>Active consumer groups</h3>
	       <ul>
			<?php foreach($consumers as $consumerId => $consumerInfo) if (!$consumerInfo['abandoned']) : ?>
				<li class="<?php echo $consumerInfo['active'] ? "active" : ($consumerInfo['abandoned'] ? "abandoned" : "inactive");?>">
					'<b><?php echo $consumerId;?></b>' consumer group of <b><?php echo count($consumerInfo['process'])?></b> active processes consuming:
					<ul>
					<?php foreach($consumerInfo['topics'] as $topicName => $consumerTopic): ?>
						<li class="<?php echo  $consumerTopic['lagging'] ? "lagging" : ($consumerTopic['active'] ? "active" :  "inactive");?>">
						<b><?php echo $topicName;?></b> {
						<?php foreach($consumerTopic['partition'] as $partitionId => $consumerPartitionStatus): ?>
							[<?php echo $partitionId;?>]:<?php echo $consumerPartitionStatus['progress'] ;?> %
						<?php endforeach;?> 
						} using <b><?php echo count($consumerTopic['process'])?></b> active streams
						</li>
					<?php endforeach;?>
					</ul> 
				</li>
			<?php endif;?>
			</ul>
        </td>
        <td>
            <h3 class="abandoned">Abandoned consumer groups</h3>
            <ul>
	        <?php foreach($consumers as $consumerId => $consumerInfo) if ($consumerInfo['abandoned']) : ?>
	        <li class="abandoned">
	        	'<b><?php echo $consumerId;?></b>' had consumed:
	        	<?php foreach($consumerInfo['topics'] as $topicName => $consumerTopic): ?>
	        	  <small><?php echo $topicName;?></small>, 
	        	<?php endforeach; ?>
	        </li>
	        <?php endif;?>
	        </ul>
	    </td>
	    <?php endif;?>
	    </tr>
	</table>
	
</body>