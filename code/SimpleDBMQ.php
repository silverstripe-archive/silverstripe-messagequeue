<?php

/**
 * Simple message queueing implementor. Queues messages in a database table.
 *
 * @author Mark Stephens <mark@silverstripe.com>
 */

class SimpleDBMQ extends DataObject implements MessageQueueImplementation
{
    private static $db = array(
        "QueueName" => "Varchar(255)",
        "Header" => "Text",
        "Message" => "Text"
    );

    public function send($queue, $msgframe, $interfaceConfig)
    {
        $msg = new SimpleDBMQ();
        $msg->QueueName = $queue;
        $msg->Message = $msgframe->body;
        $msg->Header = serialize($msgframe->header);
        $msg->write();
    }

    /**
     * @param String $queue
     * @param <type> $interfaceConfig
     * @param <type> $options
     * @return <type>
     */
    public function receive($queue, $interfaceConfig, $options)
    {
        $result = new ArrayList();
        $limit = ($options && isset($options["limit"])) ? $options["limit"] : null;

        $conn = DB::getConn();

        // Work within a transaction
        if ($conn->supportsTransactions()) {
            $conn->transactionStart();
        }

        try {
            $msgs = SimpleDBMQ::get();
            if ($queue) {
                $msgs = $msgs->filter(array("QueueName"=>$queue));
            }
            if ($limit) {
                $msgs = $msgs->limit($limit, 0);
            }

            if (!$msgs) {
                return $result;
            }

            foreach ($msgs as $do) {
                $result->push(new MessageFrame($do->Message, unserialize($do->Header), $do->QueueName));
                $do->delete();
                $do->flushCache();
            }

            // Commit transaction
            if ($conn->supportsTransactions()) {
                $conn->transactionEnd();
            }
        } catch (Exception $e) {
            // Rollback
            if ($conn->supportsTransactions()) {
                $conn->transactionRollback();
            }
            throw $e;
        }

        return $result;
    }
}
